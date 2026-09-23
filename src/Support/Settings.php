<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** Reads Prosetta's config with its defaults, so no other class repeats them. */
final readonly class Settings {
    private const array MODELS = [
        'locale' => \LonelyLights\Prosetta\Models\Locale::class,
        'file' => \LonelyLights\Prosetta\Models\TranslationFile::class,
        'key' => \LonelyLights\Prosetta\Models\TranslationKey::class,
        'translation' => \LonelyLights\Prosetta\Models\Translation::class,
        'review' => \LonelyLights\Prosetta\Models\TranslationReview::class,
    ];

    public static function sourceLocale(): string {
        return (string) config('prosetta.source_locale', 'en');
    }

    public static function table(string $name): string {
        return (string) (config("prosetta.table_names.$name") ?? "prosetta_$name");
    }

    /** @return class-string */
    public static function model(string $name): string {
        return (string) (config("prosetta.models.$name") ?? self::MODELS[$name]);
    }
}
