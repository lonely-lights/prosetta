<?php

use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\WorkState;

function stateKey(): TranslationKey {
    $file = TranslationFile::query()->create(['namespace' => '*', 'group' => 'auth', 'format' => FileFormat::Php]);

    return TranslationKey::query()->create(['file_id' => $file->id, 'key' => 'failed', 'source_value' => 'Failed.', 'source_hash' => 'h2']);
}

it('classifies every state a key can be in for one locale', function (?array $attributes, bool $missing, bool $stale, bool $candidate, bool $work) {
    $key = stateKey();
    $translation = $attributes === null ? null : new Translation($attributes);

    expect(WorkState::isMissing($key, $translation))->toBe($missing)
        ->and(WorkState::isStale($key, $translation))->toBe($stale)
        ->and(WorkState::hasCurrentCandidate($key, $translation))->toBe($candidate)
        ->and(WorkState::needsWork($key, $translation))->toBe($work);
})->with([
    'no row' => [null, true, false, false, true],
    'current draft' => [['value' => 'x', 'source_hash' => 'h2', 'status' => TranslationStatus::Draft], false, false, true, false],
    'rejected, nothing approved' => [['value' => 'x', 'source_hash' => 'h2', 'status' => TranslationStatus::Rejected], true, false, false, true],
    'approved and current' => [['value' => 'x', 'source_hash' => 'h2', 'approved_value' => 'x', 'approved_source_hash' => 'h2', 'status' => TranslationStatus::Approved], false, false, false, false],
    'approved but stale' => [['value' => 'x', 'source_hash' => 'h1', 'approved_value' => 'x', 'approved_source_hash' => 'h1', 'status' => TranslationStatus::Approved], false, true, false, true],
    'stale approved, fresh draft waiting' => [['value' => 'y', 'source_hash' => 'h2', 'approved_value' => 'x', 'approved_source_hash' => 'h1', 'status' => TranslationStatus::Draft], false, true, true, false],
    'stale draft, nothing approved' => [['value' => 'x', 'source_hash' => 'h1', 'status' => TranslationStatus::Draft], false, false, false, true],
]);
