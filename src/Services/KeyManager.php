<?php

namespace LonelyLights\Prosetta\Services;

use Illuminate\Support\Facades\Log;
use LonelyLights\Prosetta\Queue;

/**
 * Key Manager Service
 *
 * Handles translation key operations including prefixing, affixation,
 * and key removal from language files.
 *
 * @package LonelyLights\Prosetta\Services
 */
class KeyManager {
    /**
     * Generate an array of prefixed keys.
     *
     * If the affix is empty, keys remain unchanged.
     *
     * @param array $keys Array of base keys.
     * @param array|string|null $affix Affix to be applied to each key (optional).
     * @return array Array of (prefixed/suffixed) keys.
     */
    public static function generatePrefixedKeys(array $keys, array|string $affix = null): array {
        return array_map(
            fn($key) => $affix !== null ? self::applyAffixation($affix, $key) : $key,
            $keys
        );
    }

    /**
     * Applies an affix to a given key based on the specified configuration.
     *
     * This method handles both string and array types for affixes. For string affixes,
     * it applies them as either a prefix or suffix, based on configuration. For array affixes,
     * it handles 'only', 'prefix', and 'suffix' types.
     *
     * @param string|array $affix The affix to be applied. Can be a string or array.
     *                            If arrayed, expects two elements: the affix and its position.
     * @param string $key The key to which the affix is to be applied.
     * @return string The key with the affix applied.
     */
    public static function applyAffixation(string|array $affix, string $key): string {
        $affixationType = config('prosetta.affixationType', '.');
        $affixationDefault = config('prosetta.affixationDefault', 'prefix');

        // Handle string affix
        if (is_string($affix)) {
            if ($affixationDefault === config('prosetta.prefix', 'prefix')) {
                return $affix . $affixationType . $key;
            } elseif ($affixationDefault === config('prosetta.suffix', 'suffix')) {
                return $key . $affixationType . $affix;
            }
        }

        // Handle array affix
        if (is_array($affix) && count($affix) === 2) {
            [$firstAffix, $affixOrPos] = $affix;

            // Check for 'only' type
            if ($affixOrPos === config('prosetta.only', 'only')) {
                return $firstAffix;
            } elseif ($affixOrPos === config('prosetta.prefix', 'prefix')) {
                return $firstAffix . $affixationType . $key;
            } elseif ($affixOrPos === config('prosetta.suffix', 'suffix')) {
                return $key . $affixationType . $firstAffix;
            } else {
                // If neither, add both (prefix and suffix)
                return $firstAffix . $affixationType . $key . $affixationType . $affixOrPos;
            }
        }

        // Fallback for unsupported affix types
        return $key;
    }

    /**
     * Checks if any affix attribute has changed on the model.
     *
     * @param mixed $model The model instance being checked.
     * @param string|array|null $affixAttribute The attribute(s) to check for changes.
     * @return bool True if any affix attribute has changed.
     */
    public static function hasAffixChanged(mixed $model, string|array|null $affixAttribute): bool {
        if ($affixAttribute === null) {
            return false;
        }

        $affixAttributes = is_array($affixAttribute) ? $affixAttribute : [$affixAttribute];

        foreach ($affixAttributes as $attr) {
            if ($model->isDirty($attr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove specified keys from a language file.
     *
     * @param string $filePath Relative path to the language file.
     * @param string $languageCode Language code for the file.
     * @param array|string $keys Key(s) to be removed.
     * @param array|string|null $affix Optional prefix/suffix to be applied to each key.
     * @param string|null $reason Reason for the removal (for logging).
     * @param string $localeOperation The operation mode for handling locales.
     * @return void
     */
    public static function removeKeys(
        string            $filePath,
        string            $languageCode,
        array|string      $keys,
        array|string|null $affix = null,
        ?string           $reason = null,
        string            $localeOperation = 'all-keep'
    ): void {
        $reason = $reason ?? 'Entry Deleted';
        $keysToRemove = self::generatePrefixedKeys(is_array($keys) ? $keys : [$keys], $affix);

        // Remove from the queue
        foreach ($keysToRemove as $affixedKey) {
            Queue::where('key', $affixedKey)
                ->where('path', $filePath)
                ->delete();

            Log::channel(config('prosetta.logChannel', 'default'))->info('Queue entry removed.', [
                'key' => $affixedKey,
                'path' => $filePath,
            ]);
        }

        // Construct the full path
        $localPath = "lang/$languageCode/$filePath.php";
        $fullPath = base_path($localPath);

        // Exit early if file doesn't exist
        if (!file_exists($fullPath)) {
            return;
        }

        // Load existing language data
        $langData = include $fullPath;

        // Track if any key was removed
        $keyRemoved = false;

        // Remove the keys
        foreach ($keysToRemove as $k) {
            if (isset($langData[$k])) {
                unset($langData[$k]);
                $keyRemoved = true;
            }
        }

        // Exit if no keys were removed
        if (!$keyRemoved) {
            return;
        }

        // Write updated data
        FileExporter::writeToFile($fullPath, $langData);

        // Handle other locales
        if (in_array($localeOperation, ['all-keep', 'all-clear'])) {
            $activeLocales = config('prosetta.locales');
            foreach ($activeLocales as $otherLocale) {
                if ($otherLocale !== $languageCode) {
                    $otherLocaleFullPath = base_path("lang/$otherLocale/$filePath.php");
                    if (file_exists($otherLocaleFullPath)) {
                        $otherLocaleLangData = include $otherLocaleFullPath;
                        foreach ($keysToRemove as $k) {
                            if (isset($otherLocaleLangData[$k])) {
                                unset($otherLocaleLangData[$k]);
                            }
                        }
                        FileExporter::writeToFile($otherLocaleFullPath, $otherLocaleLangData);
                    }
                }
            }
        }

        Log::channel(config('prosetta.logChannel', 'default'))->info('Language file updated - keys removed.', [
            'path' => $localPath,
            'keys' => $keysToRemove,
            'reason' => $reason,
        ]);
    }
}
