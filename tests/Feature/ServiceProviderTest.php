<?php

use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\Settings;

it('merges the package config', function () {
    expect(config('prosetta.source_locale'))->toBe('en')
        ->and(config('prosetta.exclude_paths'))->toBe(['lang/vendor', 'vendor']);
});

it('resolves table names from table_names', function () {
    expect(Settings::table('locales'))->toBe('prosetta_locales');

    config()->set('prosetta.table_names.locales', 'custom_locales');

    expect(Settings::table('locales'))->toBe('custom_locales');
});

it('ignores the removed legacy camelCase table key', function () {
    config()->set('prosetta.table_names.locales', null);
    config()->set('prosetta.tableNames.locales', 'legacy_locales');

    expect(Settings::table('locales'))->toBe('prosetta_locales');
});

it('resolves model classes from config', function () {
    config()->set('prosetta.models.locale', 'App\\Models\\Locale');

    expect(Settings::model('locale'))->toBe('App\\Models\\Locale')
        ->and(Settings::model('file'))->toBe('LonelyLights\\Prosetta\\Models\\TranslationFile');
});

it('fingerprints values with sha256', function () {
    expect(Fingerprint::of('abc'))->toBe(hash('sha256', 'abc'))->toHaveLength(64);
});

it('ships the resilience and budget defaults', function () {
    expect(config('prosetta.resilience.circuit'))->toBe(['failure_threshold' => 5, 'cooldown' => 300, 'cooldown_multiplier' => 2, 'max_cooldown' => 3600])
        ->and(config('prosetta.resilience.outage_timeout'))->toBe(21600)
        ->and(config('prosetta.resilience.halt_hold'))->toBeNull()
        ->and(config('prosetta.resilience.unknown_errors'))->toBe('transient')
        ->and(config('prosetta.resilience.resume_every'))->toBeNull()
        ->and(config('prosetta.budgets.daily'))->toBeNull()
        ->and(config('prosetta.budgets.estimate'))->toBe(['input_per_char' => 0.3, 'output_per_char' => 0.3, 'input_per_item' => 12, 'output_per_item' => 8]);
});

it('keeps a driver result compatible when it reports no refusals', function () {
    $result = new \LonelyLights\Prosetta\Data\TranslationBatchResult(['1' => 'Hola'], 'fake', 'm');

    expect($result->refused)->toBe([]);
});
