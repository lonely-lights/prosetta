<?php

use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationItem;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Jobs\FinishCycle;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;
use LonelyLights\Prosetta\Translation\Translator;

const SAFEGUARD_THROTTLE = 'Too many login attempts. Please try again in :seconds seconds.';

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
    Locale::query()->where('locale_initials', 'es')->update(['auto_translate' => true]);
});

function safeguardTranslation(string $ref, string $locale): ?Translation {
    return Translation::query()->where('key_id', app(KeyFinder::class)->find($ref)->id)->where('locale', $locale)->first();
}

/** @return list<string> "{locale} {ref}" of every item the driver was sent */
function safeguardSent(ScriptedDriver $driver): array {
    return collect($driver->calls)
        ->flatMap(fn (TranslationBatch $batch) => array_map(fn (TranslationItem $item) => $batch->target->code.' '.$item->keyRef, $batch->items))
        ->values()->all();
}

function safeguardReplaceInFile(string $path, string $from, string $to): void {
    file_put_contents($path, str_replace($from, $to, file_get_contents($path)));
}

# F1: a Key the Provider Keeps Failing Is Capped and Reported

it('counts a refused key towards flagged in the cycle that failed it, and keeps a failure count', function () {
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->refuse(SAFEGUARD_THROTTLE));

    $report = app(Cycle::class)->run(sync: true);

    $throttleId = (string) app(KeyFinder::class)->find('auth.throttle')->id;
    $flagged = collect($report->flagged)->first(fn (string $line) => str_starts_with($line, 'es auth.throttle '));

    expect($flagged)->toContain('failed')
        ->and(State::get('cycle.failures')['es'][$throttleId]['count'])->toBe(1)
        ->and(State::get('cycle.failures')['es'][$throttleId]['hash'])->toBe(app(KeyFinder::class)->find('auth.throttle')->source_hash);
});

it('stops sending a key after 3 failed cycles, reports it as skipped, and sends it again once its English changes', function () {
    $driver = (new ScriptedDriver)->refuse(SAFEGUARD_THROTTLE);
    app()->instance(TranslationDriver::class, $driver);

    foreach ([1, 2, 3] as $cycle) {
        $driver->calls = [];
        app(Cycle::class)->run(sync: true);

        expect(safeguardSent($driver))->toContain('es auth.throttle');
    }

    $driver->calls = [];
    $report = app(Cycle::class)->run(sync: true);

    expect(safeguardSent($driver))->not->toContain('es auth.throttle')
        ->and(collect($report->flagged)->first(fn (string $line) => str_starts_with($line, 'es auth.throttle ')))->toContain('skipped');

    safeguardReplaceInFile($this->fixture.'/lang/en/auth.php', 'Too many login attempts.', 'Too many sign-in attempts.');
    $driver->calls = [];
    app(Cycle::class)->run(sync: true);

    expect(safeguardSent($driver))->toContain('es auth.throttle');
});

it('exits 1 from prosetta:cycle --sync while a capped key is skipped', function () {
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->refuse(SAFEGUARD_THROTTLE));

    foreach ([1, 2, 3] as $cycle) {
        app(Cycle::class)->run(sync: true);
    }

    $this->artisan('prosetta:cycle --sync')->expectsOutputToContain('es auth.throttle')->assertExitCode(1);
});

it('clears a key\'s failure count once a draft succeeds', function () {
    # An Adopted Site: Prosetta Generated Its Files, so the Cycle May Write Them
    app(Exporter::class)->export();
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->refuse(SAFEGUARD_THROTTLE));
    app(Cycle::class)->run(sync: true);
    app(Cycle::class)->run(sync: true);
    $throttleId = (string) app(KeyFinder::class)->find('auth.throttle')->id;

    expect(State::get('cycle.failures')['es'][$throttleId]['count'])->toBe(2);

    app()->instance(TranslationDriver::class, new ScriptedDriver);
    $report = app(Cycle::class)->run(sync: true);

    expect(State::get('cycle.failures', [])['es'][$throttleId] ?? null)->toBeNull()
        ->and($report->flagged)->toBe([]);
});

it('counts failures on the queued path and reports them from FinishCycle', function () {
    # A Fake Batch Stands In for the Live One; batchId Is What the Runner Records Usage and Failures Under
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->refuse(SAFEGUARD_THROTTLE));
    $throttle = app(KeyFinder::class)->find('auth.throttle');
    $scope = new RunScope(['es'], [], [], false, now()->getTimestamp(), cycle: true);
    $job = (new TranslateBatch('es', (int) $throttle->file_id, [(int) $throttle->id], false, $scope->toArray()))->withBatchId('batch-f1')->withFakeQueueInteractions();
    $job->withFakeBatch('batch-f1');

    $job->handle(app(TranslationRunner::class), app(Suspensions::class), app(Circuits::class));
    $report = (new FinishCycle('batch-f1', 'batch-f1', now()->getTimestamp() - 5, 0))->handle(app(Cycle::class));

    expect(State::get('cycle.failures')['es'][(string) $throttle->id]['count'])->toBe(1)
        ->and(collect($report->flagged)->first(fn (string $line) => str_starts_with($line, 'es auth.throttle ')))->toContain('failed');
});

it('does not count failures from a manual prosetta:translate run', function () {
    app()->instance(TranslationDriver::class, (new ScriptedDriver)->refuse(SAFEGUARD_THROTTLE));

    app(Translator::class)->translate(['es'], queue: false);

    expect(State::get('cycle.failures'))->toBeNull();
});

# F4: the Cycle's Export Never Reverts a Hand Edit Still Awaiting Review

it('leaves a lang file holding a current hand edit out of the cycle\'s export, and reports it', function () {
    $esAuth = $this->fixture.'/lang/es/auth.php';
    safeguardReplaceInFile($esAuth, 'Estas credenciales no coinciden', 'Estos datos no coinciden');
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Cycle::class)->run(sync: true);

    $files = array_map(fn (string $path) => str_replace('\\', '/', $path), $report->files);
    $held = collect($report->flagged)->first(fn (string $line) => str_contains($line, 'es/auth.php'));

    expect(safeguardTranslation('auth.failed', 'es')->origin)->toBe(TranslationOrigin::Manual)
        ->and(safeguardTranslation('auth.throttle', 'es')->status)->toBe(TranslationStatus::Approved)
        ->and(file_get_contents($esAuth))->toContain('Estos datos no coinciden')
        ->and($files)->not->toContain($this->fixture.'/lang/es/auth.php')
        ->and($files)->toContain($this->fixture.'/lang/es/messages.php')
        ->and($held)->toStartWith('es ')->toContain('held');
});

it('leaves a lang file Prosetta did not generate out of the cycle\'s export, and reports it', function () {
    $esAuth = $this->fixture.'/lang/es/auth.php';
    $before = file_get_contents($esAuth);
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Cycle::class)->run(sync: true);

    $held = collect($report->flagged)->first(fn (string $line) => str_contains($line, 'es/auth.php'));

    expect(safeguardTranslation('auth.throttle', 'es')->status)->toBe(TranslationStatus::Approved)
        ->and(file_get_contents($esAuth))->toBe($before)
        ->and($held)->toStartWith('es ')->toContain('not generated by Prosetta');
});

it('writes a file in later cycles once prosetta:export has generated it', function () {
    $esAuth = $this->fixture.'/lang/es/auth.php';
    $this->artisan('prosetta:export')->assertSuccessful();
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $report = app(Cycle::class)->run(sync: true);

    $files = array_map(fn (string $path) => str_replace('\\', '/', $path), $report->files);

    expect($files)->toContain($this->fixture.'/lang/es/auth.php')
        ->and(collect($report->flagged)->filter(fn (string $line) => str_contains($line, 'es/auth.php'))->all())->toBe([])
        ->and(file_get_contents($esAuth))->toContain('Generated by Prosetta');
});

it('still lets prosetta:export write a file holding a hand edit', function () {
    $esAuth = $this->fixture.'/lang/es/auth.php';
    safeguardReplaceInFile($esAuth, 'Estas credenciales no coinciden', 'Estos datos no coinciden');
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    app(Cycle::class)->run(sync: true);

    $this->artisan('prosetta:export')->assertSuccessful();

    expect(file_get_contents($esAuth))->toContain('Estas credenciales no coinciden');
});

# F5: the AI Never Overwrites a Person's Pending Candidate

it('keeps a manual candidate when its English changes, sends that key to no AI, and flags it as awaiting human review', function () {
    Locale::query()->where('locale_initials', 'es')->update(['auto_translate' => false]);
    app(ReviewService::class)->write('auth.failed', 'es', 'Mi propia traducción.', null);
    safeguardReplaceInFile($this->fixture.'/lang/en/auth.php', 'These credentials do not match our records.', 'These details do not match our records.');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Cycle::class)->run(sync: true);

    $manual = safeguardTranslation('auth.failed', 'es');
    expect(safeguardSent($driver))->not->toContain('es auth.failed')
        ->and($manual->value)->toBe('Mi propia traducción.')
        ->and($manual->origin)->toBe(TranslationOrigin::Manual)
        ->and($manual->status)->toBe(TranslationStatus::NeedsReview)
        ->and($report->flagged)->toContain('es auth.failed (awaiting human review)');
});

it('keeps an imported candidate awaiting review out of an auto language\'s work too', function () {
    $welcome = app(KeyFinder::class)->find('messages.welcome');
    Translation::query()->create([
        'key_id' => $welcome->id, 'locale' => 'es', 'value' => 'Hola', 'source_hash' => 'older-english',
        'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Imported,
    ]);
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Cycle::class)->run(sync: true);

    expect(safeguardSent($driver))->not->toContain('es messages.welcome')
        ->and(safeguardTranslation('messages.welcome', 'es')->value)->toBe('Hola')
        ->and($report->flagged)->toContain('es messages.welcome (awaiting human review)');
});
