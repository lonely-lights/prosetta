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
