<?php

use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;
use LonelyLights\Prosetta\Models\Locale;

beforeEach(fn () => $this->seedLocales());

it('adds the automation columns and tables', function () {
    expect(Schema::hasColumns('prosetta_locales', ['auto_translate', 'style_note', 'glossary']))->toBeTrue()
        ->and(Schema::hasColumn('prosetta_translations', 'approved_source_value'))->toBeTrue()
        ->and(Schema::hasTable('prosetta_usage'))->toBeTrue()
        ->and(Schema::hasTable('prosetta_state'))->toBeTrue();
});

it('carries the style note and glossary to the descriptor', function () {
    Locale::findByCode('es')->update([
        'style_note' => 'Use tú.',
        'glossary' => [['source' => 'cohort', 'target' => 'cohorte', 'banned' => ['grupo']]],
    ]);

    $descriptor = app(DatabaseLocaleSource::class)->find('es');

    expect($descriptor->styleNote)->toBe('Use tú.')
        ->and($descriptor->glossary)->toBe([['source' => 'cohort', 'target' => 'cohorte', 'banned' => ['grupo']]]);
});

it('lets a regional locale inherit its language\'s note and glossary', function () {
    Locale::findByCode('en')->update(['style_note' => 'US spelling.', 'glossary' => [['source' => 'color', 'target' => 'colour', 'banned' => []]]]);

    $descriptor = app(DatabaseLocaleSource::class)->find('en_GB');

    expect($descriptor->styleNote)->toBe('US spelling.')
        ->and($descriptor->glossary)->toHaveCount(1);
});

it('lists the auto-translate targets', function () {
    Locale::findByCode('es')->update(['auto_translate' => true]);

    expect(app(DatabaseLocaleSource::class)->autoTranslateTargets())->toBe(['es']);
});

it('ships automation off by default', function () {
    expect(config('prosetta.automation'))->toBe(['every' => null, 'approve' => 'all', 'export' => true, 'rewrite_ratio' => 3.0]);
});
