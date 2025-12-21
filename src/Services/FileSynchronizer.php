<?php

namespace LonelyLights\Prosetta\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * File Synchronizer Service
 *
 * Orchestrates synchronization between models and language files.
 * This is the main entry point for managing language file entries.
 *
 * @package LonelyLights\Prosetta\Services
 */
class FileSynchronizer
{
    /**
     * Manage language file entry for a model.
     *
     * Main entry point for creating/updating language file entries.
     * Handles both single and bulk operations by accepting keys and values as arrays.
     *
     * @param mixed $model The model instance from which values are extracted.
     * @param string $filePath Path to the language file relative to the 'lang' directory.
     * @param string $languageCode Language code representing the target language file.
     * @param array $keys Array of keys to be managed.
     * @param string $localeOperation The operation mode for handling locales.
     * @param string|null $method The method being used (create/update).
     * @param string|array|null $affix Optional affix to be added to each key.
     * @param string|array|null $oldAffix Optional old affix (for processing other languages).
     * @param array $capturedData Optional array of captured data from other locales.
     * @return void
     * @throws Exception
     */
    public static function manageLanguageFileEntry(
        mixed $model,
        string $filePath,
        string $languageCode,
        array $keys,
        string $localeOperation,
        ?string $method = null,
        string|array|null $affix = null,
        string|array|null $oldAffix = null,
        array $capturedData = []
    ): void {
        // Validate paths
        if (!preg_match('/^[a-zA-Z0-9-_\/]+$/', $filePath)) {
            throw new InvalidArgumentException("Invalid file path: $filePath");
        }

        if (!preg_match('/^[a-zA-Z0-9-_]+$/', $languageCode)) {
            throw new InvalidArgumentException("Invalid language code: $languageCode");
        }

        // Set paths
        $localPath = "lang/$languageCode/$filePath.php";
        $fullPath = base_path($localPath);

        // Build key-value pairs with affixation
        $keyValuePairs = TranslationService::buildKeyValuePairs(
            $keys,
            $model,
            $affix,
            $oldAffix,
            $capturedData
        );

        // Check visibility
        $visibilityCheck = TranslationService::checkVisibility(
            $model,
            $filePath,
            $languageCode,
            $keyValuePairs
        );

        $fullUpdateNeeded = $visibilityCheck['fullUpdateNeeded'];

        if ($visibilityCheck['earlyExit']) {
            return;
        }

        // Full update if affix changes
        foreach ($keyValuePairs as $keyValuePair) {
            if ($keyValuePair['originalKey'] !== $keyValuePair['originalAffixedKey']) {
                $fullUpdateNeeded = true;
                break;
            }
        }

        // Ensure file exists
        FileExporter::ensureFileExists($fullPath, $localPath);

        // Load existing language data
        $langData = include $fullPath;

        // Process key-value pairs
        self::processKeyValuePairs(
            $model,
            $keyValuePairs,
            $langData,
            $languageCode,
            $filePath,
            $fullUpdateNeeded,
            $localeOperation,
            $languageCode,
            $method
        );

        // Save language data
        FileExporter::writeToFile($fullPath, $langData);

        // Handle other locales
        if (in_array($localeOperation, ['all-keep', 'all-clear'])) {
            $activeLocales = config('prosetta.locales');
            foreach ($activeLocales as $otherLocale) {
                if ($otherLocale !== $languageCode) {
                    $otherLocaleFullPath = base_path("lang/$otherLocale/$filePath.php");
                    $otherLocalePath = "lang/$otherLocale/$filePath.php";

                    FileExporter::ensureFileExists($otherLocaleFullPath, $otherLocalePath);

                    $otherLocaleLangData = file_exists($otherLocaleFullPath)
                        ? include $otherLocaleFullPath
                        : [];

                    self::processKeyValuePairs(
                        $model,
                        $keyValuePairs,
                        $otherLocaleLangData,
                        $otherLocale,
                        $filePath,
                        $fullUpdateNeeded,
                        $localeOperation,
                        $languageCode,
                        $method
                    );

                    FileExporter::writeToFile($otherLocaleFullPath, $otherLocaleLangData);
                }
            }
        }
    }

    /**
     * Process key-value pairs and update language data.
     *
     * @param Model $model The model instance.
     * @param array $keyValuePairs Array of key-value pairs to process.
     * @param array &$langData Reference to the language data array.
     * @param string $languageCode Language code being processed.
     * @param string $filePath Path to the language file.
     * @param bool $fullUpdateNeeded Whether a full update is required.
     * @param string|null $localeOperation The locale operation mode.
     * @param string|null $baseLanguage The base language code.
     * @param string|null $method The operation method (create/update).
     * @return void
     */
    private static function processKeyValuePairs(
        Model $model,
        array $keyValuePairs,
        array &$langData,
        string $languageCode,
        string $filePath,
        bool $fullUpdateNeeded,
        ?string $localeOperation = null,
        ?string $baseLanguage = null,
        ?string $method = null
    ): void {
        if ($fullUpdateNeeded) {
            foreach ($keyValuePairs as $keyValuePair) {
                $valueToSet = $languageCode === $baseLanguage
                    ? $keyValuePair['value']
                    : ($keyValuePair['isDirty'] === true
                        ? ($localeOperation === 'all-clear' ? '' : ($keyValuePair[$languageCode] ?? ''))
                        : ($keyValuePair[$languageCode] ?? ''));

                FileExporter::processKeyValue(
                    $keyValuePair['affixedKey'],
                    $valueToSet,
                    $langData,
                    $languageCode,
                    $filePath
                );

                if ($baseLanguage !== $languageCode && $method !== null) {
                    TranslationService::enqueueTranslationTask(
                        $model,
                        $method,
                        $keyValuePair,
                        $baseLanguage,
                        $languageCode,
                        $filePath
                    );
                }
            }
        } else {
            foreach ($keyValuePairs as $keyValuePair) {
                if ($keyValuePair['isDirty']) {
                    $valueToSet = ($languageCode === $baseLanguage)
                        ? $keyValuePair['value']
                        : ($localeOperation === 'all-clear'
                            ? ''
                            : ($method === 'create' ? '' : null));

                    if ($valueToSet !== null) {
                        FileExporter::processKeyValue(
                            $keyValuePair['affixedKey'],
                            $valueToSet,
                            $langData,
                            $languageCode,
                            $filePath
                        );
                    }

                    if ($baseLanguage !== $languageCode && $method !== null) {
                        TranslationService::enqueueTranslationTask(
                            $model,
                            $method,
                            $keyValuePair,
                            $baseLanguage,
                            $languageCode,
                            $filePath
                        );
                    }
                }
            }
        }
    }

    /**
     * Capture data from language files for given keys and locales.
     *
     * Used when affix changes to preserve translations from other locales.
     *
     * @param array $keys The keys to capture data for.
     * @param string $filePath The file path within the language files.
     * @param string|array $oldAffix The affix to be applied to the keys.
     * @return array An associative array of captured data by locale.
     */
    public static function captureLocaleData(array $keys, string $filePath, string|array $oldAffix): array
    {
        $capturedData = [];
        $activeLocales = config('prosetta.locales');

        foreach ($activeLocales as $otherLocale) {
            $otherLocaleFullPath = base_path("lang/$otherLocale/$filePath.php");

            if (file_exists($otherLocaleFullPath)) {
                $otherLocaleLangData = include $otherLocaleFullPath;

                foreach ($keys as $key) {
                    $keyWithOldAffix = KeyManager::applyAffixation($oldAffix, $key);
                    if (isset($otherLocaleLangData[$keyWithOldAffix])) {
                        $capturedData[$otherLocale][$keyWithOldAffix] = $otherLocaleLangData[$keyWithOldAffix];
                    }
                }
            }
        }

        return $capturedData;
    }

    /**
     * Update language entries when a specific field changes.
     *
     * Main entry point for updating language entries when model changes.
     *
     * @param mixed $model The model instance being updated.
     * @param string $filePath Relative path to the language file.
     * @param string $languageCode Language code for the file.
     * @param array $keys Array of keys associated with the model.
     * @param string $localeOperation The operation mode for handling locales.
     * @param string|null $method The method being used (create/update).
     * @param array|string|null $affix Current affix for the keys.
     * @param mixed $oldAffix Old affix for the keys before the update.
     * @return void
     * @throws Exception
     */
    public static function updateLangEntries(
        mixed $model,
        string $filePath,
        string $languageCode,
        array $keys,
        string $localeOperation,
        ?string $method = null,
        array|string|null $affix = null,
        mixed $oldAffix = null
    ): void {
        $capturedData = [];

        // Remove old keys if affix changed
        if (KeyManager::hasAffixChanged($model, TranslationService::getAffixAttribute())) {
            $reason = 'Affix Updated';

            // Capture old data before removing
            if (in_array($localeOperation, ['all-keep', 'all-clear'])) {
                $capturedData = self::captureLocaleData($keys, $filePath, $oldAffix);
            }

            KeyManager::removeKeys($filePath, $languageCode, $keys, $oldAffix, $reason, $localeOperation);
        }

        // Update language file for the current locale
        if (isset($oldAffix) && !empty($capturedData)) {
            self::manageLanguageFileEntry(
                $model,
                $filePath,
                $languageCode,
                $keys,
                $localeOperation,
                $method,
                $affix,
                $oldAffix,
                $capturedData
            );
        } else {
            self::manageLanguageFileEntry(
                $model,
                $filePath,
                $languageCode,
                $keys,
                $localeOperation,
                $method,
                $affix
            );
        }
    }
}
