<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Events\TranslationSuspended;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRateLimited;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderRejected;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    config([
        'prosetta.resilience.cache_store' => 'array',
        'prosetta.resilience.jitter' => 0,
        'prosetta.resilience.backoff' => [30, 60],
        'prosetta.resilience.circuit' => ['failure_threshold' => 2, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600],
    ]);
    Event::fake([TranslationSuspended::class]);
});

function resilientJob(ScriptedDriver $driver): TranslateBatch {
    app()->instance(TranslationDriver::class, $driver);
    # Ruling R3: auth.throttle, because auth.failed already has an approved es fixture translation and the runner would skip it
    $id = app(KeyFinder::class)->find('auth.throttle')->id;

    return (new TranslateBatch('es', 1, [$id], false, (new RunScope(['es'], ['*'], []))->toArray()))->withFakeQueueInteractions();
}

function handle(TranslateBatch $job): void {
    $job->handle(app(TranslationRunner::class), app(Suspensions::class), app(Circuits::class));
}

it('backs off an unavailable provider by attempt', function () {
    $job = resilientJob((new ScriptedDriver)->fail(new ProviderUnavailable('down')));
    handle($job);

    $job->assertReleased(30);
});

it('waits as long as a rate limit asks', function () {
    $job = resilientJob((new ScriptedDriver)->fail(new ProviderRateLimited(retryAfter: 42)));
    handle($job);

    $job->assertReleased(42);
});

it('waits out an open circuit without calling the provider', function () {
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'));
    handle(resilientJob($driver));
    handle(resilientJob($driver));

    $job = resilientJob($driver);
    handle($job);

    $job->assertReleased(300);
    expect($driver->calls)->toHaveCount(2);
});

it('suspends quietly on a halt instead of failing', function () {
    $job = resilientJob((new ScriptedDriver)->fail(new ProviderRejected('bad key')));
    handle($job);

    $job->assertDeleted();
    $job->assertNotFailed();
    expect(app(Suspensions::class)->all())->toHaveCount(1);
    Event::assertDispatched(TranslationSuspended::class, fn (TranslationSuspended $event) => $event->reason === 'rejected');
});

it('suspends once the outage outlasts outage_timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 600]);
    # Ruling R1: a third failure, so the test-phase call after the travel fails too
    $driver = (new ScriptedDriver)->fail(new ProviderUnavailable('down'), new ProviderUnavailable('down'), new ProviderUnavailable('still down'));
    handle(resilientJob($driver));
    handle(resilientJob($driver));
    $this->travel(700)->seconds();

    $job = resilientJob($driver);
    handle($job);

    $job->assertDeleted();
    expect(app(Suspensions::class)->all())->toHaveCount(1);
});

it('keeps retryUntil beyond the outage timeout', function () {
    config(['prosetta.resilience.outage_timeout' => 21600, 'prosetta.resilience.circuit.max_cooldown' => 3600]);

    expect((new TranslateBatch('es', 1, [1]))->retryUntil()->getTimestamp())
        ->toBeGreaterThanOrEqual(now()->addSeconds(21600 + 3600)->getTimestamp());
});

it('stops a synchronous run at the first provider problem and suspends it', function () {
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->fail(new ProviderRejected('bad key')));

    $report = app(Translator::class)->translate(['es'], ['*'], queue: false);

    expect($report->stopped)->toContain('bad key')
        ->and(app(Suspensions::class)->all())->toHaveCount(1);
});

it('stops a synchronous run at its own budget without suspending it', function () {
    config(['prosetta.budgets.per_run' => 1]);
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Translator::class)->translate(['es'], ['*'], queue: false);

    expect($report->stopped)->toContain('per_run')
        ->and(app(Suspensions::class)->all())->toBe([]);
});
