<?php

use Illuminate\Bus\PendingBatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Events\TranslationResumed;
use LonelyLights\Prosetta\Exceptions\MissingDriverException;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderUnavailable;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\HealthCheckedScriptedDriver;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
});

function suspendSpanish(string $circuit): void {
    app(Suspensions::class)->suspend($circuit, new RunScope(['es'], ['*'], []), 'outage');
}

it('requeues suspended work when the circuit is closed', function () {
    Bus::fake();
    Event::fake([TranslationResumed::class]);
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    suspendSpanish('scripted-driver:default');

    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatchCount(1);
    expect(app(Suspensions::class)->all())->toBe([]);
    Event::assertDispatched(TranslationResumed::class, fn (TranslationResumed $event) => $event->scopes === 1);
});

it('leaves work suspended while the cooldown runs', function () {
    Bus::fake();
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    $circuit = app(Circuits::class)->for('scripted-driver:default');
    foreach (range(1, 5) as $ignored) {
        $circuit->recordFailure('down');
    }
    suspendSpanish('scripted-driver:default');

    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertNothingBatched();
    expect(app(Suspensions::class)->all())->toHaveCount(1);
});

it('tests with the health check after the cooldown and resumes only when it passes', function () {
    Bus::fake();
    $driver = (new HealthCheckedScriptedDriver)->failHealth(new ProviderUnavailable('still down'));
    app()->instance(TranslationDriver::class, $driver);
    $circuit = app(Circuits::class)->for('health-checked-scripted-driver:default');
    foreach (range(1, 5) as $ignored) {
        $circuit->recordFailure('down');
    }
    suspendSpanish('health-checked-scripted-driver:default');
    $this->travel(301)->seconds();

    $this->artisan('prosetta:resume')->assertSuccessful();
    Bus::assertNothingBatched();

    $this->travel(601)->seconds();
    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatchCount(1);
    expect($driver->healthChecks)->toBe(2)
        ->and(app(Suspensions::class)->all())->toBe([]);
});

it('waits for the next day when the daily budget is spent', function () {
    Bus::fake();
    config(['prosetta.budgets.daily' => 10]);
    app(\LonelyLights\Prosetta\Resilience\Budget::class)->record(null, 10);
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    suspendSpanish('budget');

    $this->artisan('prosetta:resume')->assertSuccessful();
    Bus::assertNothingBatched();

    $this->travelTo(now()->addDay()->startOfDay()->addMinute());
    $this->artisan('prosetta:resume')->assertSuccessful();
    Bus::assertBatchCount(1);
});

it('resuming twice queues nothing new', function () {
    Bus::fake();
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    suspendSpanish('scripted-driver:default');

    $this->artisan('prosetta:resume')->assertSuccessful();
    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatchCount(1);
});

it('shows and resets circuits', function () {
    $circuit = app(Circuits::class)->for('scripted-driver:default');
    $circuit->trip('rejected', 'bad key');

    $this->artisan('prosetta:circuit')->expectsOutputToContain('scripted-driver:default')->assertSuccessful();
    $this->artisan('prosetta:circuit reset')->assertSuccessful();

    expect($circuit->decision()->kind)->toBe('call');
});

it('schedules resume when resume_every is set', function () {
    config(['prosetta.resilience.resume_every' => 10]);
    (new \LonelyLights\Prosetta\ProsettaServiceProvider(app()))->boot();

    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'prosetta:resume'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/10 * * * *');
});

it('logs circuit and suspension events', function () {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    event(new \LonelyLights\Prosetta\Events\CircuitOpened('scripted-driver:default', 300, 5, 'down'));
});

it('resumes a forced run without re-translating what it already drafted', function () {
    Bus::fake();
    config(['prosetta.ai.batch' => 1]);
    $ids = collect(app(Translator::class)->workList(['es'], ['identity']))->flatten()->all();
    expect($ids)->toHaveCount(2);
    $driver = new class extends ScriptedDriver {
        public function translate(TranslationBatch $batch): TranslationBatchResult {
            if ($this->calls !== []) {
                $this->fail(new ProviderUnavailable('down'));
            }

            return parent::translate($batch);
        }
    };
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Translator::class)->translate(['es'], ['identity'], force: true, queue: false);
    expect($report->drafted)->toHaveCount(1)
        ->and($report->stopped)->toContain('down');

    $suspended = array_values(app(Suspensions::class)->all());
    expect($suspended)->toHaveCount(1)
        ->and($suspended[0]['scope']->force)->toBeTrue()
        ->and($suspended[0]['scope']->startedAt)->toBe(now()->getTimestamp());

    $this->travel(10)->seconds();
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->flatMap(fn (TranslateBatch $job) => $job->keyIds)->all() === [$ids[1]]
        && $batch->jobs->first()->force === true
        && $batch->jobs->first()->scope['started_at'] === now()->subSeconds(10)->getTimestamp());
});

it('merges repeated suspensions of the same run whatever their start time', function () {
    app(Suspensions::class)->suspend('x:m', new RunScope(['es'], [], [], true, 100), 'outage');
    app(Suspensions::class)->suspend('x:m', new RunScope(['es'], [], [], true, 200), 'outage');

    expect(app(Suspensions::class)->all())->toHaveCount(1)
        ->and(RunScope::fromArray((new RunScope(['es'], [], [], true, 100))->toArray())->startedAt)->toBe(100);
});

it('keeps a suspension when requeueing it throws', function () {
    Bus::fake();
    suspendSpanish('budget');

    expect(fn () => $this->artisan('prosetta:resume')->run())->toThrow(MissingDriverException::class);

    expect(app(Suspensions::class)->all())->toHaveCount(1);
    Bus::assertNothingBatched();
});

it('keeps a suspension that a job records again while resume is requeueing it', function () {
    Bus::fake();
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    suspendSpanish('scripted-driver:default');
    $inner = app(LocaleSource::class);
    app()->instance(LocaleSource::class, new class($inner) implements LocaleSource {
        public function __construct(private LocaleSource $inner) {}

        public function source(): string {
            return $this->inner->source();
        }

        public function targets(): array {
            # A Job From the Requeued Run Suspends the Same Scope Again While translate() Is Still Running
            suspendSpanish('scripted-driver:default');

            return $this->inner->targets();
        }

        public function find(string $code): ?LocaleDescriptor {
            return $this->inner->find($code);
        }
    });
    app()->forgetInstance(Translator::class);

    $this->artisan('prosetta:resume')->assertSuccessful();

    Bus::assertBatchCount(1);
    expect(app(Suspensions::class)->all())->toHaveCount(1);
});

it('gives the test turn back when the driver cannot be built during resume', function () {
    app()->bind(TranslationDriver::class, fn () => throw new \Illuminate\Contracts\Container\BindingResolutionException('missing API key'));
    $circuit = app(Circuits::class)->for('scripted-driver:default');
    foreach (range(1, 5) as $ignored) {
        $circuit->recordFailure('down');
    }
    suspendSpanish('scripted-driver:default');
    $this->travel(301)->seconds();

    expect(fn () => $this->artisan('prosetta:resume')->run())
        ->toThrow(\LonelyLights\Prosetta\Exceptions\ProsettaException::class, 'could not be built');
    expect(\LonelyLights\Prosetta\Support\Settings::cacheStore()->lock('prosetta:circuit:scripted-driver:default:test', 1)->get())->toBeTrue();
});
