<?php

use LonelyLights\Prosetta\Content\ContentKeys;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->directory = $this->useFixtureApp();
    $this->seedLocales();
});

it('keeps content keys current through a full sync, though no lang folder holds them', function () {
    $file = app(ContentKeys::class)->file('pillars');
    $key = TranslationKey::query()->create([
        'file_id' => $file->id, 'kind' => KeyKind::Content, 'key' => 'technology.name',
        'source_value' => 'Technology', 'source_hash' => sha1('Technology'),
    ]);

    app(Syncer::class)->sync();

    expect($file->refresh()->format)->toBe(FileFormat::Database)
        ->and($file->namespace)->toBe('content')
        ->and($key->refresh()->obsolete_at)->toBeNull();
});

it('reuses one folder per group', function () {
    $first = app(ContentKeys::class)->file('pillars');
    $again = app(ContentKeys::class)->file('pillars');

    expect($again->id)->toBe($first->id);
});

it('writes no file for content on export', function () {
    $file = app(ContentKeys::class)->file('pillars');
    TranslationKey::query()->create([
        'file_id' => $file->id, 'kind' => KeyKind::Content, 'key' => 'technology.name',
        'source_value' => 'Technology', 'source_hash' => sha1('Technology'),
    ]);

    app(Exporter::class)->export();

    expect(glob($this->directory.'/lang/*/pillars.php'))->toBe([])
        ->and(is_dir($this->directory.'/lang/content'))->toBeFalse();
});
