<?php

namespace LonelyLights\Prosetta\Traits;

use LonelyLights\Prosetta\Services\FileExporter;
use LonelyLights\Prosetta\Services\FileSynchronizer;
use LonelyLights\Prosetta\Services\KeyManager;
use LonelyLights\Prosetta\Services\TranslationService;

/**
 * HasTranslations Trait
 *
 * Enables automatic synchronization of model data to language files.
 * When a model is created, updated, or deleted, the corresponding
 * translation entries are automatically managed in the lang files.
 *
 * ## Basic Usage
 *
 * ```php
 * class Entry extends Model
 * {
 *     use HasTranslations;
 *
 *     // Specify which fields should be translated
 *     protected array $translatable = ['title', 'description', 'content'];
 * }
 * ```
 *
 * ## Configuration Options
 *
 * - `$translatable` - Array of field names to translate (required)
 * - `$translationPath` - Path to the lang file (default: table name)
 * - `$translationAffix` - Attribute(s) to use as key prefix (optional)
 * - `$translationLocaleOperation` - How to handle other locales (default: 'all-keep')
 *
 * ## Advanced Usage
 *
 * ```php
 * class Entry extends Model
 * {
 *     use HasTranslations;
 *
 *     protected array $translatable = ['title', 'description'];
 *     protected string $translationPath = 'projects/entries';
 *     protected string|array $translationAffix = 'slug';
 *     protected string $translationLocaleOperation = 'all-keep';
 * }
 * ```
 *
 * @package LonelyLights\Prosetta\Traits
 */
trait HasTranslations
{
    /**
     * Boot the HasTranslations trait.
     *
     * Registers model event listeners for created, updated, and deleted events.
     *
     * @return void
     */
    public static function bootHasTranslations(): void
    {
        // On model created
        static::created(function ($model) {
            $model->syncTranslations('create');
        });

        // On model updated
        static::updated(function ($model) {
            $model->syncTranslations('update');
        });

        // On model deleted (including soft deletes)
        static::deleted(function ($model) {
            $model->removeTranslations();
        });
    }

    /**
     * Get the fields that should be translated.
     *
     * Override this method or set the $translatable property.
     *
     * @return array
     */
    public function getTranslatableFields(): array
    {
        return $this->translatable ?? [];
    }

    /**
     * Get the path for the translation file.
     *
     * Override this method or set the $translationPath property.
     * Defaults to the table name.
     *
     * @return string
     */
    public function getTranslationPath(): string
    {
        return $this->translationPath ?? $this->getTable();
    }

    /**
     * Get the affix attribute(s) for translation keys.
     *
     * Override this method or set the $translationAffix property.
     * The affix is prepended/appended to each key for uniqueness.
     *
     * @return string|array|null
     */
    public function getTranslationAffix(): string|array|null
    {
        return $this->translationAffix ?? null;
    }

    /**
     * Get the current affix value from the model.
     *
     * @return string|array|null
     */
    public function getTranslationAffixValue(): string|array|null
    {
        $affixAttr = $this->getTranslationAffix();

        if ($affixAttr === null) {
            return null;
        }

        if (is_array($affixAttr)) {
            return array_map(fn($attr) => $this->{$attr}, $affixAttr);
        }

        return $this->{$affixAttr};
    }

    /**
     * Get the original affix value before changes.
     *
     * @return string|array|null
     */
    public function getOriginalTranslationAffixValue(): string|array|null
    {
        $affixAttr = $this->getTranslationAffix();

        if ($affixAttr === null) {
            return null;
        }

        if (is_array($affixAttr)) {
            return array_map(fn($attr) => $this->getOriginal($attr), $affixAttr);
        }

        return $this->getOriginal($affixAttr);
    }

    /**
     * Get the locale operation mode.
     *
     * Options: 'all-keep', 'all-clear', 'current-only'
     *
     * @return string
     */
    public function getTranslationLocaleOperation(): string
    {
        return $this->translationLocaleOperation ?? config('prosetta.defaultBehavior', 'all-keep');
    }

    /**
     * Get the base language code.
     *
     * @return string
     */
    public function getTranslationBaseLanguage(): string
    {
        return $this->translationBaseLanguage ?? config('app.locale', 'en');
    }

    /**
     * Sync translations to language files.
     *
     * @param string $method The operation method ('create' or 'update')
     * @return void
     */
    public function syncTranslations(string $method = 'update'): void
    {
        $fields = $this->getTranslatableFields();

        if (empty($fields)) {
            return;
        }

        // Configure the translation service with affix attribute
        TranslationService::setConfig([
            'affixAttribute' => $this->getTranslationAffix(),
        ]);

        $filePath = $this->getTranslationPath();
        $languageCode = $this->getTranslationBaseLanguage();
        $localeOperation = $this->getTranslationLocaleOperation();
        $affix = $this->getTranslationAffixValue();
        $oldAffix = $this->getOriginalTranslationAffixValue();

        if ($method === 'create') {
            FileSynchronizer::manageLanguageFileEntry(
                $this,
                $filePath,
                $languageCode,
                $fields,
                $localeOperation,
                $method,
                $affix
            );
        } else {
            FileSynchronizer::updateLangEntries(
                $this,
                $filePath,
                $languageCode,
                $fields,
                $localeOperation,
                $method,
                $affix,
                $oldAffix
            );
        }
    }

    /**
     * Remove translations from language files.
     *
     * Called when a model is deleted.
     *
     * @return void
     */
    public function removeTranslations(): void
    {
        $fields = $this->getTranslatableFields();

        if (empty($fields)) {
            return;
        }

        $filePath = $this->getTranslationPath();
        $languageCode = $this->getTranslationBaseLanguage();
        $localeOperation = $this->getTranslationLocaleOperation();
        $affix = $this->getTranslationAffixValue();

        KeyManager::removeKeys(
            $filePath,
            $languageCode,
            $fields,
            $affix,
            'Model deleted',
            $localeOperation
        );
    }

    /**
     * Force sync all translations for this model.
     *
     * Writes all translatable fields to the language file regardless of dirty state.
     * Useful for initial population or manual re-sync.
     *
     * @return void
     */
    public function forceSyncTranslations(): void
    {
        $fields = $this->getTranslatableFields();

        if (empty($fields)) {
            return;
        }

        $filePath = $this->getTranslationPath();
        $languageCode = $this->getTranslationBaseLanguage();
        $localeOperation = $this->getTranslationLocaleOperation();
        $affix = $this->getTranslationAffixValue();

        // Build the full path
        $localPath = "lang/$languageCode/$filePath.php";
        $fullPath = base_path($localPath);

        // Ensure file exists
        FileExporter::ensureFileExists($fullPath, $localPath);

        // Load existing data
        $langData = file_exists($fullPath) ? include $fullPath : [];

        // Write all translatable fields (bypass dirty check)
        foreach ($fields as $field) {
            $value = $this->{$field};
            if ($value !== null) {
                $key = $affix !== null ? KeyManager::applyAffixation($affix, $field) : $field;
                $langData[$key] = $value;
            }
        }

        // Sort and write
        ksort($langData);
        FileExporter::writeToFile($fullPath, $langData);

        // Handle other locales
        if (in_array($localeOperation, ['all-keep', 'all-clear'])) {
            $activeLocales = config('prosetta.locales', []);
            foreach ($activeLocales as $otherLocale) {
                if ($otherLocale !== $languageCode) {
                    $otherLocalePath = "lang/$otherLocale/$filePath.php";
                    $otherFullPath = base_path($otherLocalePath);

                    FileExporter::ensureFileExists($otherFullPath, $otherLocalePath);

                    $otherLangData = file_exists($otherFullPath) ? include $otherFullPath : [];

                    // For other locales, create empty entries (to be translated later)
                    foreach ($fields as $field) {
                        $key = $affix !== null ? KeyManager::applyAffixation($affix, $field) : $field;
                        if (!isset($otherLangData[$key])) {
                            $otherLangData[$key] = '';
                        }
                    }

                    ksort($otherLangData);
                    FileExporter::writeToFile($otherFullPath, $otherLangData);
                }
            }
        }
    }

    /**
     * Get the translation value for a specific field and locale.
     *
     * @param string $field The field name
     * @param string|null $locale The locale code (defaults to current locale)
     * @return string|null
     */
    public function getTranslation(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $filePath = $this->getTranslationPath();
        $affix = $this->getTranslationAffixValue();

        $fullPath = base_path("lang/$locale/$filePath.php");

        if (!file_exists($fullPath)) {
            return null;
        }

        $langData = include $fullPath;
        $key = $affix !== null ? KeyManager::applyAffixation($affix, $field) : $field;

        return $langData[$key] ?? null;
    }

    /**
     * Get all translations for a specific field across all locales.
     *
     * @param string $field The field name
     * @return array<string, string> Associative array of locale => value
     */
    public function getAllTranslations(string $field): array
    {
        $translations = [];
        $locales = config('prosetta.locales', []);
        $filePath = $this->getTranslationPath();
        $affix = $this->getTranslationAffixValue();

        foreach ($locales as $locale) {
            $fullPath = base_path("lang/$locale/$filePath.php");

            if (file_exists($fullPath)) {
                $langData = include $fullPath;
                $key = $affix !== null ? KeyManager::applyAffixation($affix, $field) : $field;

                if (isset($langData[$key])) {
                    $translations[$locale] = $langData[$key];
                }
            }
        }

        return $translations;
    }
}
