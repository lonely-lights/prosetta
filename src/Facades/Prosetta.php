<?php

namespace LonelyLights\Prosetta\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Prosetta Facade
 *
 * Provides a clean API for interacting with the Prosetta translation system.
 *
 * @method static array sync(string $locale) Sync translation files from disk to database
 * @method static array syncAll() Sync all locales
 * @method static \Illuminate\Database\Eloquent\Collection files() Get all translation files
 * @method static \LonelyLights\Prosetta\Models\TranslationFile|null file(string $path) Get a specific translation file
 * @method static \LonelyLights\Prosetta\Models\TranslationFile createFile(string $path, array $attributes = []) Create a new translation file
 * @method static \Illuminate\Database\Eloquent\Collection keys(string $filePath) Get all keys for a file
 * @method static \LonelyLights\Prosetta\Models\Translation|null set(string $fullKey, string $value, string $locale, string $source = 'manual') Set a translation value
 * @method static string|null get(string $fullKey, string $locale, ?string $fallback = null) Get a translation value
 * @method static bool delete(string $fullKey) Delete a translation key
 * @method static bool export(string $locale, string $filePath) Export a file to disk
 * @method static array exportAll(string $locale) Export all files for a locale
 * @method static array statistics(string $locale) Get translation statistics for a locale
 * @method static \Illuminate\Database\Eloquent\Collection locales() Get all active locales
 * @method static \LonelyLights\Prosetta\Models\Locale|null defaultLocale() Get the default locale
 *
 * @see \LonelyLights\Prosetta\ProsettaManager
 *
 * @package LonelyLights\Prosetta\Facades
 */
class Prosetta extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'prosetta';
    }
}
