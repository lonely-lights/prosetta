<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use Illuminate\Support\Collection;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Settings;

/** The current keys a review screen lists, narrowed by namespace and group, in file order. */
final readonly class CurrentKeys {
    /**
     * @param array{namespace?: ?string, group?: ?string} $filters
     * @return Collection<int, TranslationKey>
     */
    public static function matching(array $filters): Collection {
        $keyModel = Settings::model('key');

        return $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get()
            ->filter(fn (TranslationKey $key) => (($filters['namespace'] ?? null) === null || $key->file->namespace === $filters['namespace'])
                && (($filters['group'] ?? null) === null || $key->file->group === $filters['group']))
            ->sortBy(fn (TranslationKey $key) => [$key->file->namespace, $key->file->group, $key->getKey()])
            ->values();
    }
}
