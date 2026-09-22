<?php

use Illuminate\Database\QueryException;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;

function makeKey(string $namespace = 'identity', string $group = 'onboarding', string $key = 'toast.accessCode.inUse', string $value = 'In use.'): TranslationKey {
    $file = TranslationFile::query()->firstOrCreate(['namespace' => $namespace, 'group' => $group], ['format' => FileFormat::Php]);

    return TranslationKey::query()->create([
        'file_id' => $file->id, 'key' => $key, 'source_value' => $value, 'source_hash' => Fingerprint::of($value),
    ]);
}

it('links files, keys, translations and reviews', function () {
    $key = makeKey();
    $translation = $key->translations()->create(['locale' => 'es', 'value' => 'En uso.', 'source_hash' => $key->source_hash]);
    $review = $translation->reviews()->create(['reviewer_id' => 'user-1', 'action' => ReviewAction::Edited, 'new_value' => 'En uso.']);

    expect($key->file->group)->toBe('onboarding')
        ->and($translation->key->is($key))->toBeTrue()
        ->and($review->translation->is($translation))->toBeTrue()
        ->and($review->action)->toBe(ReviewAction::Edited);
});

it('casts to enums and defaults new translations to manual drafts', function () {
    $key = makeKey();
    $translation = $key->translations()->create(['locale' => 'es', 'value' => 'x'])->fresh();

    expect($key->kind)->toBe(KeyKind::File)
        ->and($key->file->format)->toBe(FileFormat::Php)
        ->and($translation->status)->toBe(TranslationStatus::Draft)
        ->and($translation->origin)->toBe(TranslationOrigin::Manual);
});

it('builds a KeyRef from the key and its file', function () {
    expect(makeKey()->ref()->toString())->toBe('identity::onboarding.toast.accessCode.inUse')
        ->and(makeKey('*', '*', 'Save changes')->ref()->toString())->toBe('json:Save changes');
});

it('fingerprints the key so long JSON sentences stay unique and findable', function () {
    $long = str_repeat('A long sentence. ', 60);
    $key = makeKey('*', '*', $long);

    expect($key->key_hash)->toBe(Fingerprint::of($long))
        ->and(TranslationKey::query()->withKey($long)->first()?->is($key))->toBeTrue();
});

it('enforces one file per namespace and group, one key per file, one translation per locale', function () {
    $key = makeKey();
    $key->translations()->create(['locale' => 'es', 'value' => 'a']);

    expect(fn () => TranslationFile::query()->create(['namespace' => 'identity', 'group' => 'onboarding', 'format' => FileFormat::Php]))->toThrow(QueryException::class)
        ->and(fn () => TranslationKey::query()->create(['file_id' => $key->file_id, 'key' => $key->key, 'source_value' => 'x', 'source_hash' => 'x']))->toThrow(QueryException::class)
        ->and(fn () => $key->translations()->create(['locale' => 'es', 'value' => 'b']))->toThrow(QueryException::class);
});

it('reads every table name from config', function () {
    config()->set('prosetta.table_names.keys', 'custom_keys');

    expect((new TranslationKey)->getTable())->toBe('custom_keys')
        ->and((new Translation)->getTable())->toBe('prosetta_translations');
});

it('knows when a translation carries blocking issues', function () {
    $translation = new Translation(['issues' => [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'x']]]);

    expect($translation->hasBlockingIssues())->toBeTrue()
        ->and((new Translation)->hasBlockingIssues())->toBeFalse();
});

it('scopes to current keys', function () {
    makeKey(key: 'a');
    makeKey(key: 'b')->update(['obsolete_at' => now()]);

    expect(TranslationKey::query()->current()->pluck('key')->all())->toBe(['a']);
});
