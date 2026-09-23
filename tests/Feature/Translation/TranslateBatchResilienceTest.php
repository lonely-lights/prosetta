<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Events\TranslationSuspended;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderBatchRejected;
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

it('gives a job a horizon of days, so a long run never expires a job Prosetta has not given up on', function () {
    $horizon = (new TranslateBatch('es', 1, [1]))->retryUntil()->getTimestamp();

    expect($horizon)->toBeGreaterThanOrEqual(now()->addDays(7)->getTimestamp() - 1)
        ->and($horizon)->toBeGreaterThan(now()->addHours(24)->getTimestamp());
});

it('bounds genuine bugs with maxExceptions and a backoff', function () {
    $job = new TranslateBatch('es', 1, [1]);

    expect($job->maxExceptions)->toBe(3)
        ->and($job->backoff())->toBe([30, 120, 600]);
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

it('fails only its own job when the provider rejects the batch', function () {
    $job = resilientJob((new ScriptedDriver)->fail($error = new ProviderBatchRejected('context too long')));
    handle($job);

    $job->assertFailedWith($error);
    $job->assertNotReleased();
    expect(app(Suspensions::class)->all())->toBe([])
        ->and(app(Circuits::class)->for('scripted-driver:default')->state()['failures'])->toBe(0);
    Event::assertNotDispatched(TranslationSuspended::class);
});

it('records a rejected chunk as failed in a synchronous run and carries on', function () {
    config(['prosetta.ai.batch' => 1]);
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->fail(new ProviderBatchRejected('invalid input')));

    $report = app(Translator::class)->translate(['es'], ['identity'], queue: false);

    expect($report->stopped)->toBeNull()
        ->and($report->failed)->toHaveCount(1)
        ->and($report->drafted)->toHaveCount(1)
        ->and($report->failed[0])->toStartWith('es identity::')
        ->and(app(Suspensions::class)->all())->toBe([]);
});

it('keeps the first attempt\'s drafts when a synchronous retry gets a batch rejection', function () {
    $driver = new class extends ScriptedDriver {
        public function translate(TranslationBatch $batch): TranslationBatchResult {
            if ($batch->feedback !== []) {
                throw new ProviderBatchRejected('rejected on retry');
            }

            $result = parent::translate($batch);
            $values = array_map(fn (string $value) => (string) preg_replace('/:[A-Za-z_]\w*/', '', $value), $result->values);

            return new TranslationBatchResult($values, $result->provider, $result->model, $result->inputTokens, $result->outputTokens);
        }
    };
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Translator::class)->translate(['es'], [], ['messages.welcome'], queue: false);

    expect($report->drafted)->toBe(['es messages.welcome'])
        ->and($report->failed)->toBe([])
        ->and($report->stopped)->toBeNull();
});

it('stores the reason "rejected" for a queued halt from ProviderRejected', function () {
    $job = resilientJob((new ScriptedDriver)->fail(new ProviderRejected('bad key')));
    handle($job);

    expect(collect(app(Suspensions::class)->all())->first()['reason'])->toBe('rejected');
});

it('stores the same reason "rejected" for a synchronous halt from ProviderRejected', function () {
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->fail(new ProviderRejected('bad key')));

    app(Translator::class)->translate(['es'], ['*'], queue: false);

    expect(collect(app(Suspensions::class)->all())->first()['reason'])->toBe('rejected');
});

it('stores the reason "unknown" for a queued halt from an unrecognized error under unknown_errors=halt', function () {
    config(['prosetta.resilience.unknown_errors' => 'halt']);
    $job = resilientJob((new ScriptedDriver)->fail(new RuntimeException('boom')));
    handle($job);

    expect(collect(app(Suspensions::class)->all())->first()['reason'])->toBe('unknown');
});

it('stores the same reason "unknown" for a synchronous halt from an unrecognized error under unknown_errors=halt', function () {
    config(['prosetta.resilience.unknown_errors' => 'halt']);
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->fail(new RuntimeException('boom')));

    app(Translator::class)->translate(['es'], ['*'], queue: false);

    expect(collect(app(Suspensions::class)->all())->first()['reason'])->toBe('unknown');
});
