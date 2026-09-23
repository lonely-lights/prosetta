<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\Settings;

final readonly class KeyFinder {
    public function find(KeyRef|string $ref): ?TranslationKey {
        $ref = is_string($ref) ? KeyRef::parse($ref) : $ref;
        $fileModel = Settings::model('file');
        $file = $fileModel::query()->where('namespace', $ref->namespace)->where('group', $ref->group)->first();

        if ($file === null) {
            return null;
        }

        /** @var TranslationKey|null $key */
        $key = $file->keys()->withKey($ref->key)->with('translations')->first();
        $key?->setRelation('file', $file);

        return $key;
    }
}
