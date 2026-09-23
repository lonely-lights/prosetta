<?php

use Illuminate\Bus\PendingBatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Data\TranslationItem;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\CycleCompleted;
use LonelyLights\Prosetta\Jobs\FinishCycle;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
});

function cycleAutoTranslate(string ...$codes): void {
    Locale::query()->whereIn('locale_initials', $codes)->update(['auto_translate' => true]);
}

function cycleEditEnglish(string $fixture, string $to): void {
    $path = $fixture.'/lang/en/auth.php';
    file_put_contents($path, str_replace("'These credentials do not match our records.'", var_export($to, true), file_get_contents($path)));
}

function cycleTranslation(string $ref, string $locale): ?Translation {
    return Translation::query()->where('key_id', app(KeyFinder::class)->find($ref)->id)->where('locale', $locale)->first();
}

function cycleAiDrafts(string $locale): int {
    return Translation::query()->where('locale', $locale)->where('origin', TranslationOrigin::Ai->value)->count();
}

/** Drops placeholders from messages.welcome on every attempt; echoes everything else like the fake driver. */
function cycleIssueDriver(): ScriptedDriver {
    return new class extends ScriptedDriver {
        public function translate(TranslationBatch $batch): TranslationBatchResult {
            $this->calls[] = $batch;
            $values = [];

            foreach ($batch->items as $item) {
                /** @var TranslationItem $item */
                $value = $item->source.' ['.$batch->target->code.']';
                $values[$item->id] = $item->keyRef === 'messages.welcome' ? (string) preg_replace('/:[A-Za-z_]\w*/', '', $value) : $value;
            }

            return new TranslationBatchResult($values, 'fake', 'm', 10, 5);
        }
    };
}

it('drafts missing keys only for auto-translate languages', function () {
    cycleAutoTranslate('es');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Cycle::class)->run(sync: true);

    $esDrafts = cycleAiDrafts('es');
    expect($report->skipped)->toBeFalse()
        ->and($esDrafts)->toBeGreaterThan(0)
        ->and($report->drafted)->toBe($esDrafts)
        ->and(cycleTranslation('auth.throttle', 'es')->origin)->toBe(TranslationOrigin::Ai)
        ->and(cycleAiDrafts('ar'))->toBe(0)
        ->and(cycleAiDrafts('en_GB'))->toBe(0)
        ->and(collect($driver->calls)->map(fn (TranslationBatch $batch) => $batch->target->code)->unique()->values()->all())->toBe(['es'])
        ->and($report->tokens)->toBeGreaterThan(0)
        ->and($report->tokens)->toBe(app(UsageLedger::class)->sum());
});

it('updates an edited key in every language that has it', function () {
    app(ReviewService::class)->write('auth.failed', 'ar', 'بيانات الاعتماد هذه لا تتطابق مع سجلاتنا.', null, approve: true);
    cycleEditEnglish($this->fixture, 'These details do not match our records.');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Cycle::class)->run(sync: true);

    $items = collect($driver->calls)->flatMap(fn (TranslationBatch $batch) => array_map(fn (TranslationItem $item) => [$batch->target->code, $item->keyRef, $item->previousSource], $batch->items));
    expect($items->sortBy(0)->values()->all())->toBe([
        ['ar', 'auth.failed', 'These credentials do not match our records.'],
        ['es', 'auth.failed', 'These credentials do not match our records.'],
    ])
        ->and($report->updated)->toBe(2)
        ->and($report->drafted)->toBe(2)
        ->and($report->confirmed)->toBe(0);
});

it('confirms a cosmetic edit without calling the driver', function () {
    cycleEditEnglish($this->fixture, 'These credentials do not match our records!');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Cycle::class)->run(sync: true);

    $es = cycleTranslation('auth.failed', 'es');
    expect($driver->calls)->toBe([])
        ->and($report->confirmed)->toBe(1)
        ->and($report->drafted)->toBe(0)
        ->and($es->approved_source_hash)->toBe($es->key->source_hash)
        ->and($es->approved_source_value)->toBe('These credentials do not match our records!')
        ->and($es->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and($es->status)->toBe(TranslationStatus::Approved);
});

it('approves only drafts with no issues at all', function () {
    cycleAutoTranslate('es');
    $es = Locale::query()->where('locale_initials', 'es')->first();
    $es->update(['glossary' => [['source' => 'login', 'target' => 'sesión']]]);
    app()->instance(TranslationDriver::class, cycleIssueDriver());

    $report = app(Cycle::class)->run(sync: true);

    $welcome = cycleTranslation('messages.welcome', 'es');
    $throttle = cycleTranslation('auth.throttle', 'es');
    $approvedAi = Translation::query()->where('locale', 'es')->where('origin', TranslationOrigin::Ai->value)->where('status', TranslationStatus::Approved->value)->count();

    expect(collect($welcome->issues)->pluck('code')->all())->toContain('placeholder_missing')
        ->and(collect($throttle->issues)->pluck('code')->all())->toBe(['glossary_missing'])
        ->and($welcome->status)->toBe(TranslationStatus::Draft)
        ->and($throttle->status)->toBe(TranslationStatus::Draft)
        ->and($report->flagged)->toEqualCanonicalizing(['es messages.welcome', 'es auth.throttle'])
        ->and($report->approved)->toBe($report->drafted - 2)
        ->and($report->approved)->toBe($approvedAi)
        ->and($approvedAi)->toBeGreaterThan(0);
});

it('stops at drafts when approve is none', function () {
    config(['prosetta.automation.approve' => 'none']);
    cycleAutoTranslate('es');
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Cycle::class)->run(sync: true);

    expect($report->drafted)->toBeGreaterThan(0)
        ->and($report->approved)->toBe(0)
        ->and($report->files)->toBe([])
        ->and(Translation::query()->where('locale', 'es')->where('origin', TranslationOrigin::Ai->value)->where('status', '!=', TranslationStatus::Draft->value)->count())->toBe(0);
});

it('approves only the listed languages', function () {
    config(['prosetta.automation.approve' => ['es']]);
    cycleAutoTranslate('es', 'ar');
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Cycle::class)->run(sync: true);

    $esApproved = Translation::query()->where('locale', 'es')->where('origin', TranslationOrigin::Ai->value)->where('status', TranslationStatus::Approved->value)->count();
    expect($esApproved)->toBe(cycleAiDrafts('es'))
        ->and($esApproved)->toBeGreaterThan(0)
        ->and(cycleAiDrafts('ar'))->toBeGreaterThan(0)
        ->and(Translation::query()->where('locale', 'ar')->where('status', TranslationStatus::Approved->value)->count())->toBe(0)
        ->and($report->approved)->toBe($esApproved);
});

it('exports only languages that got approvals', function () {
    config(['prosetta.automation.approve' => ['es']]);
    cycleAutoTranslate('es', 'ar');
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Cycle::class)->run(sync: true);

    $files = array_map(fn (string $path) => str_replace('\\', '/', $path), $report->files);
    expect($files)->toContain($this->fixture.'/lang/es/messages.php')
        ->and(collect($files)->filter(fn (string $path) => str_contains($path, '/ar/') || str_ends_with($path, '/ar.json'))->all())->toBe([])
        ->and(file_exists($this->fixture.'/lang/ar/auth.php'))->toBeFalse()
        ->and(file_get_contents($this->fixture.'/lang/es/messages.php'))->toContain('Welcome, :name! [es]');
});

it('skips while the previous cycle\'s batch is unfinished, even after a cache clear', function () {
    Bus::fake();
    cycleAutoTranslate('es');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);
    # The Fake Batch Repository Keeps Batches in Memory: Unfinished, with One Pending Job
    $previous = Bus::batch([new FinishCycle('x', 'x', 0, 0)])->dispatch();
    State::put('cycle.batch', ['batch_id' => $previous->id, 'started_at' => now()->getTimestamp()]);
    Cache::store('array')->flush();

    $report = app(Cycle::class)->run(sync: true);

    expect($report->skipped)->toBeTrue()
        ->and($report->reason)->toBe('previous cycle still running')
        ->and($driver->calls)->toBe([])
        ->and(cycleAiDrafts('es'))->toBe(0)
        ->and(State::get('cycle.last_run'))->toBeNull()
        ->and(State::get('cycle.batch')['batch_id'])->toBe($previous->id);
});

it('runs when the stored batch no longer exists', function () {
    # A Pruned Batch: the Fake Repository Finds Nothing for This Id
    Bus::fake();
    cycleAutoTranslate('es');
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    State::put('cycle.batch', ['batch_id' => 'pruned-batch', 'started_at' => now()->getTimestamp()]);

    $report = app(Cycle::class)->run(sync: true);

    expect($report->skipped)->toBeFalse()
        ->and($report->drafted)->toBeGreaterThan(0)
        ->and(State::get('cycle.batch'))->toBeNull();
});

it('records the heartbeat and raises CycleCompleted, also when there is nothing to do', function () {
    Event::fake([CycleCompleted::class]);
    $before = now()->getTimestamp();

    $report = app(Cycle::class)->run(sync: true);

    expect(State::get('cycle.last_run'))->toBeGreaterThanOrEqual($before)
        ->and($report->toArray())->toMatchArray([
            'skipped' => false, 'drafted' => 0, 'updated' => 0, 'confirmed' => 0, 'approved' => 0,
            'flagged' => [], 'files' => [], 'tokens' => 0, 'batch_id' => null,
        ]);
    Event::assertDispatched(CycleCompleted::class, fn (CycleCompleted $event) => $event->report === $report);
    Event::assertDispatchedTimes(CycleCompleted::class, 1);
});

it('queues one batch and finishes it through FinishCycle', function () {
    Bus::fake();
    Event::fake([CycleCompleted::class]);
    cycleAutoTranslate('es');
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Cycle::class)->run();

    expect($report->batchId)->not->toBeNull()
        ->and(State::get('cycle.batch')['batch_id'])->toBe($report->batchId)
        ->and(State::get('cycle.last_run'))->toBeNull();
    Bus::assertBatchCount(1);
    $finally = null;
    Bus::assertBatched(function (PendingBatch $batch) use (&$finally) {
        $finally = $batch->finallyCallbacks()[0] ?? null;

        return count($batch->finallyCallbacks()) === 1 && $batch->jobs->isNotEmpty();
    });
    Event::assertNotDispatched(CycleCompleted::class);

    # The Callback Survives Serialization and Dispatches FinishCycle for Its Own Batch
    $callback = unserialize(serialize($finally));
    $callback(Bus::findBatch($report->batchId));
    Bus::assertDispatched(FinishCycle::class, fn (FinishCycle $job) => $job->batchId === $report->batchId && $job->runId === $report->batchId && $job->confirmed === 0);

    (new FinishCycle($report->batchId, $report->batchId, now()->getTimestamp() - 5, 0))->handle(app(Cycle::class));

    expect(State::get('cycle.batch'))->toBeNull()
        ->and(State::get('cycle.last_run'))->toBeGreaterThan(0);
    Event::assertDispatched(CycleCompleted::class, fn (CycleCompleted $event) => $event->report->batchId === $report->batchId);
});

it('finishes a queued cycle by counting and approving what its jobs drafted', function () {
    cycleAutoTranslate('es');
    $startedAt = now()->getTimestamp();
    $driver = cycleIssueDriver();
    app()->instance(TranslationDriver::class, $driver);
    $missing = LonelyLights\Prosetta\Models\TranslationKey::query()->whereNull('obsolete_at')->get()
        ->filter(fn ($key) => cycleTranslation($key->ref()->toString(), 'es') === null)->modelKeys();
    app(LonelyLights\Prosetta\Translation\TranslationRunner::class)->run('es', array_values($missing), false, 'batch-1');

    $report = (new FinishCycle('batch-1', 'batch-1', $startedAt, 0))->handle(app(Cycle::class));

    expect($report->drafted)->toBe(count($missing))
        ->and($report->flagged)->toBe(['es messages.welcome'])
        ->and($report->approved)->toBe(count($missing) - 1)
        ->and($report->tokens)->toBe(app(UsageLedger::class)->sum('batch-1'))
        ->and($report->tokens)->toBeGreaterThan(0);
});

it('prosetta:cycle --sync exits 1 when anything is flagged, 0 when clean', function () {
    cycleAutoTranslate('es');
    app()->instance(TranslationDriver::class, cycleIssueDriver());

    $this->artisan('prosetta:cycle --sync')->expectsOutputToContain('es messages.welcome')->assertExitCode(1);

    # Fix the Flagged Draft by Hand: Nothing Flagged, Stale or Suspended Is Left
    $welcome = cycleTranslation('messages.welcome', 'es');
    app(ReviewService::class)->edit($welcome->id, '¡Bienvenido, :name!', null, approve: true);
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $this->artisan('prosetta:cycle --sync')->assertExitCode(0);
});

it('prosetta:cycle --sync exits 1 while an approved translation is stale', function () {
    cycleEditEnglish($this->fixture, 'These details do not match our records.');
    # A Refusal Leaves No Draft, so Nothing Is Flagged or Suspended: Only the Stale Approval Remains
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->refuse('These details do not match our records.'));

    $this->artisan('prosetta:cycle --sync')->expectsOutputToContain('1 stale')->assertExitCode(1);

    expect(cycleTranslation('auth.failed', 'es')->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('prints the queued batch id without --sync', function () {
    Bus::fake();
    cycleAutoTranslate('es');
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $this->artisan('prosetta:cycle')->expectsOutputToContain('Queued cycle batch')->assertExitCode(0);

    expect(State::get('cycle.batch'))->not->toBeNull();
});

it('schedules the cycle when automation.every is set', function () {
    config(['prosetta.automation.every' => 15]);
    (new \LonelyLights\Prosetta\ProsettaServiceProvider(app()))->boot();

    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'prosetta:cycle'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/15 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('does not schedule the cycle by default', function () {
    (new \LonelyLights\Prosetta\ProsettaServiceProvider(app()))->boot();

    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'prosetta:cycle'));

    expect($events)->toHaveCount(0);
});

it('approves only its own clean drafts, never an older draft or a person\'s pending edit', function () {
    cycleAutoTranslate('es');
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    $this->travel(-1)->hours();
    app(LonelyLights\Prosetta\Translation\TranslationRunner::class)->run('es', [app(KeyFinder::class)->find('auth.throttle')->id]);
    $this->travelBack();
    app(ReviewService::class)->write('messages.welcome', 'es', '¡Bienvenido, :name!', null);

    $report = app(Cycle::class)->run(sync: true);

    $older = cycleTranslation('auth.throttle', 'es');
    $manual = cycleTranslation('messages.welcome', 'es');
    expect($older->status)->toBe(TranslationStatus::Draft)
        ->and($older->issues)->toBeNull()
        ->and($manual->status)->toBe(TranslationStatus::NeedsReview)
        ->and($manual->issues)->toBeNull()
        ->and(cycleTranslation('messages.terms', 'es')->status)->toBe(TranslationStatus::Approved)
        ->and($report->drafted)->toBeGreaterThan(0)
        ->and($report->approved)->toBe($report->drafted);
});

it('re-drafts an unapproved AI draft in an auto language when its English changes', function () {
    cycleAutoTranslate('es');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);
    $throttle = app(KeyFinder::class)->find('auth.throttle');
    app(LonelyLights\Prosetta\Translation\TranslationRunner::class)->run('es', [$throttle->id]);
    config(['prosetta.automation.approve' => 'none']);
    $path = $this->fixture.'/lang/en/auth.php';
    file_put_contents($path, str_replace('Too many login attempts.', 'Too many sign-in attempts.', file_get_contents($path)));
    $driver->calls = [];

    app(Cycle::class)->run(sync: true);

    $sent = collect($driver->calls)->flatMap(fn (TranslationBatch $batch) => array_map(fn (TranslationItem $item) => $item->keyRef, $batch->items));
    $draft = cycleTranslation('auth.throttle', 'es');
    expect($sent)->toContain('auth.throttle')
        ->and($draft->value)->toBe('Too many sign-in attempts. Please try again in :seconds seconds. [es]')
        ->and($draft->source_hash)->toBe($draft->key->source_hash)
        ->and($draft->status)->toBe(TranslationStatus::Draft);
});

it('clears a suspended cycle run on resume instead of re-translating its scope', function () {
    Bus::fake();
    $suspensions = app(LonelyLights\Prosetta\Resilience\Suspensions::class);
    $scope = new LonelyLights\Prosetta\Resilience\RunScope(['es', 'ar'], [], [], false, now()->getTimestamp(), cycle: true);
    $suspensions->suspend('scripted-driver:default', $scope, 'outage');

    expect(LonelyLights\Prosetta\Resilience\RunScope::fromArray($scope->toArray())->cycle)->toBeTrue()
        ->and($scope->id())->toBe((new LonelyLights\Prosetta\Resilience\RunScope(['es', 'ar'], [], []))->id());

    $this->artisan('prosetta:resume')->expectsOutputToContain('Cleared 1 suspended cycle run(s)')->assertSuccessful();

    Bus::assertNothingBatched();
    expect($suspensions->all())->toBe([]);
});
