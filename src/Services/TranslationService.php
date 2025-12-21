<?php

namespace LonelyLights\Prosetta\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Translation Service
 *
 * Handles model metadata extraction and visibility checks for translatable content.
 *
 * @package LonelyLights\Prosetta\Services
 */
class TranslationService {
    /**
     * Service configuration - affix attribute(s) to watch.
     *
     * @var string|array|null
     */
    protected static string|array|null $languageAffixAttribute = null;

    /**
     * Sets configuration parameters for the TranslationService.
     *
     * @param array $config Configuration parameters.
     * @return void
     */
    public static function setConfig(array $config): void {
        static::$languageAffixAttribute = $config['affixAttribute'] ?? null;
    }

    /**
     * Get the current affix attribute configuration.
     *
     * @return string|array|null
     */
    public static function getAffixAttribute(): string|array|null {
        return static::$languageAffixAttribute;
    }

    /**
     * Map keys to their corresponding values from the given model.
     *
     * @param array $keys Array of keys to be mapped.
     * @param Model $model Model instance to fetch values from.
     * @return array Mapped values.
     */
    public static function mapToValues(array $keys, Model $model): array {
        return array_map(fn($key) => $model->{$key}, $keys);
    }

    /**
     * Get details of the model for translation context.
     *
     * Extracts model description from the modelDescription property if available.
     *
     * @param Model $model The model instance.
     * @param string $key The key for which details are needed.
     * @return array Array containing model details.
     */
    public static function getModelDetails(Model $model, string $key): array {
        $modelDetails = [];

        if (property_exists($model, 'modelDescription')) {
            if (is_array($model->modelDescription)) {
                $modelDetails['description'] = $model->modelDescription[$key] ?? null;
            } else {
                $modelDetails['description'] = $model->modelDescription;
            }
        } else {
            $modelDetails['description'] = null;
        }

        return $modelDetails;
    }

    /**
     * Check the visibility of model attributes and determine if keys should be visible.
     *
     * @param Model $model The model instance being processed.
     * @param string $filePath Path to the language file.
     * @param string $languageCode Language code.
     * @param array $keyValuePairs Array of key-value pairs.
     * @return array Associative array with 'fullUpdateNeeded' and 'earlyExit' flags.
     * @throws Exception
     */
    public static function checkVisibility(
        Model  $model,
        string $filePath,
        string $languageCode,
        array  $keyValuePairs
    ): array {
        $fullUpdateNeeded = false;
        $earlyExit = false;
        $ignoreValues = config('prosetta.visibility.ignore_values', []);
        $visibilityColumns = config('prosetta.visibility.columns', []);

        foreach ($visibilityColumns as $column) {
            $columnValue = $model->{$column} ?? null;

            // Convert boolean values to strings
            if (is_bool($columnValue)) {
                $columnValue = $columnValue ? 'public' : 'private';
            }

            // If visibility is off, ensure keys aren't visible
            if (in_array($columnValue, $ignoreValues, true)) {
                $reason = 'Removed due to visibility settings.';
                $affixedKeysToRemove = array_map(fn($pair) => $pair['affixedKey'], $keyValuePairs);
                KeyManager::removeKeys($filePath, $languageCode, $affixedKeysToRemove, null, $reason);
                $earlyExit = true;
                break;
            }

            if ($model->isDirty($column)) {
                $fullUpdateNeeded = true;
            }
        }

        return [
            'fullUpdateNeeded' => $fullUpdateNeeded,
            'earlyExit' => $earlyExit,
        ];
    }

    /**
     * Enqueue a translation task.
     *
     * @deprecated Queue system removed. This method is now a no-op.
     *
     * @param Model $model The model associated with the translation task.
     * @param string $method The operation method (create/update).
     * @param array $keyValuePair Key-value pair containing data for translation.
     * @param string $baseLanguage The original language of the content.
     * @param string $languageCode The target language code for translation.
     * @param string $filePath Path to the file where translation is processed.
     * @return void
     */
    public static function enqueueTranslationTask(
        Model  $model,
        string $method,
        array  $keyValuePair,
        string $baseLanguage,
        string $languageCode,
        string $filePath
    ): void {
        // Queue system removed - this is now a no-op
        // Future: integrate with new database-driven translation system
    }

    /**
     * Build key-value pairs with affixation and metadata.
     *
     * @param array $keys Array of keys to process.
     * @param Model $model The model instance.
     * @param string|array|null $affix Current affix to apply.
     * @param string|array|null $oldAffix Old affix for comparison.
     * @param array $capturedData Captured data from other locales.
     * @return array Array of key-value pairs with metadata.
     */
    public static function buildKeyValuePairs(
        array             $keys,
        Model             $model,
        string|array|null $affix,
        string|array|null $oldAffix,
        array             $capturedData = []
    ): array {
        return array_map(function ($key) use ($model, $affix, $oldAffix, $capturedData) {
            $affixedKey = $affix !== null ? KeyManager::applyAffixation($affix, $key) : $key;
            $originalAffixedKey = $oldAffix !== null ? KeyManager::applyAffixation($oldAffix, $key) : $key;

            $pair = [
                'isDirty' => $model->isDirty($key),
                'originalKey' => $key,
                'originalAffixedKey' => $originalAffixedKey,
                'affixedKey' => $affixedKey,
                'value' => $model->{$key},
                'oldValue' => $model->getOriginal($key),
            ];

            // Add captured data from other locales
            foreach ($capturedData as $lang => $data) {
                if (isset($data[$originalAffixedKey])) {
                    $pair[$lang] = $data[$originalAffixedKey];
                }
            }

            return $pair;
        }, $keys);
    }
}
