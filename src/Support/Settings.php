<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Models\TranslationReport;
use LonelyLights\Prosetta\Models\TranslationReview;
use LonelyLights\Prosetta\Resilience\CacheStore;

/** Reads Prosetta's config with its defaults, so no other class repeats them. */
final readonly class Settings {
    private const array MODELS = [
        'locale' => Locale::class,
        'file' => TranslationFile::class,
        'key' => TranslationKey::class,
        'translation' => Translation::class,
        'review' => TranslationReview::class,
        'report' => TranslationReport::class,
    ];

    public static function sourceLocale(): string {
        return (string) config('prosetta.source_locale', 'en');
    }

    public static function table(string $name): string {
        return (string) (config("prosetta.table_names.$name") ?? "prosetta_$name");
    }

    /**
     * @return ($name is 'locale' ? class-string<Locale> : ($name is 'file' ? class-string<TranslationFile> : ($name is 'key' ? class-string<TranslationKey> : ($name is 'translation' ? class-string<Translation> : ($name is 'review' ? class-string<TranslationReview> : class-string<TranslationReport>)))))
     */
    public static function model(string $name): string {
        // @phpstan-ignore return.type (a configured model must extend the one it replaces)
        return (string) (config("prosetta.models.$name") ?? self::MODELS[$name]);
    }

    /** The cache store that holds circuits, budgets and suspended work. */
    public static function cache(): Repository {
        $store = config('prosetta.resilience.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    /** The resilience cache, wrapped so cache errors surface as ProsettaExceptions and locks come from the store. */
    public static function cacheStore(): CacheStore {
        return new CacheStore(self::cache());
    }
}
