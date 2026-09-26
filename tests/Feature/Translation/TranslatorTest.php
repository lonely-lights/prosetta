<?php

use Illuminate\Bus\PendingBatch;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Exceptions\MissingDriverException;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use LonelyLights\Prosetta\Translation\TranslateReport;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

it('builds a work list of missing and stale keys per locale and file', function () {
    $work = app(Translator::class)->workList(['es'], ['identity']);
    $file = app(KeyFinder::class)->find('identity::onboarding.toast.accessCode.capReached')->file_id;

    expect(array_keys($work))->toBe(['es'])
        ->and($work['es'][$file])->toHaveCount(2);
});

it('narrows the work list to named keys', function () {
    $id = app(KeyFinder::class)->find('auth.throttle')->id;
    $work = app(Translator::class)->workList([], [], ['auth.throttle']);

    expect(array_keys($work))->toBe(['es', 'ar', 'en_GB'])
        ->and(collect($work)->flatten()->all())->toBe([$id, $id, $id]);
});

it('queues one batch of jobs on the configured queue', function () {
    Bus::fake();
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    config()->set('prosetta.ai.batch', 2);

    app(Translator::class)->translate(['es'], ['identity']);

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === 'prosetta:translate'
        && $batch->jobs->count() === 1
        && $batch->queue() === 'translations'
        && $batch->jobs->first() instanceof TranslateBatch);
});

it('runs inline when asked', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);

    $report = app(Translator::class)->translate(['es'], ['identity'], queue: false);

    expect($report)->toBeInstanceOf(TranslateReport::class)
        ->and($report->drafted)->toHaveCount(2);
});

it('rate-limits and de-duplicates its jobs', function () {
    $middleware = (new TranslateBatch('es', 1, [1, 2]))->middleware();

    expect($middleware[0])->toBeInstanceOf(RateLimited::class)
        ->and($middleware[1])->toBeInstanceOf(WithoutOverlapping::class);
});

it('survives being released for rate limiting or overlap without exhausting its attempts', function () {
    $job = new TranslateBatch('es', 1, [1, 2]);
    $middleware = $job->middleware();

    expect($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(6)->getTimestamp())
        ->and($job->timeout)->toBeGreaterThan(0)
        ->and($middleware[1]->expiresAfter)->toBeGreaterThan(0);
});

it('refuses to queue work when no driver is bound', function () {
    Bus::fake();

    expect(fn () => app(Translator::class)->translate(['es'], ['identity']))
        ->toThrow(MissingDriverException::class);

    Bus::assertNothingBatched();
});
