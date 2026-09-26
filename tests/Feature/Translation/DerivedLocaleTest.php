<?php

use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
    Locale::query()->where('locale_initials', 'en_GB')->update(['auto_translate' => true, 'replacements' => [
        ['from' => 'credentials', 'to' => 'details'],
        ['from' => 'records', 'to' => 'files'],
    ]]);
});

function derivedTranslation(string $ref): ?Translation {
    return Translation::query()->where('key_id', app(KeyFinder::class)->find($ref)->id)->where('locale', 'en_GB')->first();
}

it('exports only the strings a derived locale changes, keeping a list whole when any item changes', function () {
    Locale::query()->where('locale_initials', 'en_GB')->update(['replacements' => [
        ['from' => 'credentials', 'to' => 'details'],
        ['from' => 'records', 'to' => 'files'],
        ['from' => 'language', 'to' => 'tongue'],
    ]]);
    app(TranslationRunner::class)->run('en_GB', TranslationKey::query()->pluck('id')->map(fn ($id) => (int) $id)->all(), force: true);
    app(ReviewService::class)->approveClean('en_GB');

    app(Exporter::class)->export(['en_GB']);

    expect(require $this->fixture.'/lang/en_GB/auth.php')->toBe(['failed' => 'These details do not match our files.'])
        ->and(require $this->fixture.'/lang/en_GB/admin/settings.php')->toBe(['steps' => ['Open the menu', 'Choose a tongue']])
        ->and(file_exists($this->fixture.'/lang/en_GB.json'))->toBeFalse();
});

it('carries a locale\'s word replacements on its descriptor', function () {
    expect(app(DatabaseLocaleSource::class)->find('en_GB')->replacements)->toBe([
        ['from' => 'credentials', 'to' => 'details'],
        ['from' => 'records', 'to' => 'files'],
    ]);
});

it('derives a locale with word replacements from the English, with no driver and no tokens', function () {
    $report = app(TranslationRunner::class)->run('en_GB', TranslationKey::query()->pluck('id')->map(fn ($id) => (int) $id)->all(), force: true);
    $failed = derivedTranslation('auth.failed');

    expect($failed->value)->toBe('These details do not match our files.')
        ->and($failed->origin)->toBe(TranslationOrigin::Derived)
        ->and($failed->status)->toBe(TranslationStatus::Draft)
        ->and($failed->issues)->toBeNull()
        ->and(derivedTranslation('messages.welcome')->value)->toBe('Welcome, :name!')
        ->and($report->inputTokens + $report->outputTokens)->toBe(0)
        ->and(DB::table(Settings::table('usage'))->count())->toBe(0);
});

it('updates, approves and exports a derived locale in the cycle without sending it to the driver', function () {
    # An Adopted Site: Prosetta Generated Its Files, so the Cycle May Write Them
    app(Exporter::class)->export();
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Those credentials do not match our records.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    $report = app(Cycle::class)->run(sync: true);
    $failed = derivedTranslation('auth.failed');

    expect($failed->approved_value)->toBe('Those details do not match our files.')
        ->and($failed->origin)->toBe(TranslationOrigin::Derived)
        ->and($failed->status)->toBe(TranslationStatus::Approved)
        ->and(collect($driver->calls)->map(fn (TranslationBatch $batch) => $batch->target->code)->all())->not->toContain('en_GB')
        ->and(require $this->fixture.'/lang/en_GB/auth.php')->toMatchArray(['failed' => 'Those details do not match our files.'])
        ->and(collect($report->flagged)->filter(fn (string $line) => str_starts_with($line, 'en_GB '))->all())->toBe([]);
});
