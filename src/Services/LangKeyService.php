<?php

namespace LonelyLights\Prosetta\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Language Key Service (Deprecated)
 *
 * This class is deprecated and will be removed in v0.4.
 * All methods delegate to the new focused services:
 *
 * - KeyManager: Key operations (prefixing, affixation, removal)
 * - FileExporter: File writing and formatting
 * - TranslationService: Queue management and model metadata
 * - FileSynchronizer: Orchestration between models and files
 *
 * Migration Guide:
 * - LangKeyService::generatePrefixedKeys() → KeyManager::generatePrefixedKeys()
 * - LangKeyService::mapToValues() → TranslationService::mapToValues()
 * - LangKeyService::removeKeys() → KeyManager::removeKeys()
 * - LangKeyService::manageLanguageFileEntry() → FileSynchronizer::manageLanguageFileEntry()
 * - LangKeyService::updateLangEntries() → FileSynchronizer::updateLangEntries()
 * - LangKeyService::processKeyValue() → FileExporter::processKeyValue()
 * - LangKeyService::setConfig() → TranslationService::setConfig()
 *
 * @package LonelyLights\Prosetta\Services
 * @deprecated Use KeyManager, FileExporter, TranslationService, or FileSynchronizer instead.
 */
class LangKeyService
{
    /**
     * Sets configuration parameters for the service.
     *
     * @param array $config Configuration parameters.
     * @return void
     * @deprecated Use TranslationService::setConfig() instead.
     */
    public static function setConfig(array $config): void
    {
        trigger_error(
            'LangKeyService::setConfig() is deprecated. Use TranslationService::setConfig() instead.',
            E_USER_DEPRECATED
        );
        TranslationService::setConfig($config);
    }

    /**
     * Manage language file entry for a model.
     *
     * @param mixed $model The model instance.
     * @param string $filePath Path to the language file.
     * @param string $languageCode Language code.
     * @param array $keys Array of keys.
     * @param string $localeOperation Operation mode.
     * @param string|null $method Method being used.
     * @param string|array|null $affix Optional affix.
     * @param string|array|null $oldAffix Optional old affix.
     * @param array $capturedData Optional captured data.
     * @return void
     * @throws Exception
     * @deprecated Use FileSynchronizer::manageLanguageFileEntry() instead.
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
        trigger_error(
            'LangKeyService::manageLanguageFileEntry() is deprecated. Use FileSynchronizer::manageLanguageFileEntry() instead.',
            E_USER_DEPRECATED
        );
        FileSynchronizer::manageLanguageFileEntry(
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
    }

    /**
     * Generate an array of prefixed keys.
     *
     * @param array $keys Array of base keys.
     * @param array|string|null $affix Affix to be applied.
     * @return array Array of prefixed keys.
     * @deprecated Use KeyManager::generatePrefixedKeys() instead.
     */
    public static function generatePrefixedKeys(array $keys, array|string $affix = null): array
    {
        // Don't trigger deprecation for this commonly used method during transition
        return KeyManager::generatePrefixedKeys($keys, $affix);
    }

    /**
     * Map keys to their corresponding values from the model.
     *
     * @param array $keys Array of keys.
     * @param Model $model Model instance.
     * @return array Mapped values.
     * @deprecated Use TranslationService::mapToValues() instead.
     */
    public static function mapToValues(array $keys, Model $model): array
    {
        // Don't trigger deprecation for this commonly used method during transition
        return TranslationService::mapToValues($keys, $model);
    }

    /**
     * Process and validate a single key-value pair.
     *
     * @param string $key Key of the entry.
     * @param string $value Value for the entry.
     * @param array &$langData Reference to language data array.
     * @param string $languageCode Language code.
     * @param string $filePath Path to language file.
     * @return void
     * @deprecated Use FileExporter::processKeyValue() instead.
     */
    public static function processKeyValue(
        string $key,
        string $value,
        array &$langData,
        string $languageCode,
        string $filePath
    ): void {
        trigger_error(
            'LangKeyService::processKeyValue() is deprecated. Use FileExporter::processKeyValue() instead.',
            E_USER_DEPRECATED
        );
        FileExporter::processKeyValue($key, $value, $langData, $languageCode, $filePath);
    }

    /**
     * Remove specified keys from a language file.
     *
     * @param string $filePath Relative path to the language file.
     * @param string $languageCode Language code.
     * @param array|string $keys Keys to be removed.
     * @param array|string|null $affix Optional affix.
     * @param string|null $reason Reason for removal.
     * @param string $localeOperation Operation mode.
     * @return void
     * @deprecated Use KeyManager::removeKeys() instead.
     */
    public static function removeKeys(
        string $filePath,
        string $languageCode,
        array|string $keys,
        array|string|null $affix = null,
        ?string $reason = null,
        string $localeOperation = 'all-keep'
    ): void {
        trigger_error(
            'LangKeyService::removeKeys() is deprecated. Use KeyManager::removeKeys() instead.',
            E_USER_DEPRECATED
        );
        KeyManager::removeKeys($filePath, $languageCode, $keys, $affix, $reason, $localeOperation);
    }

    /**
     * Update keys in the language file when a field changes.
     *
     * @param mixed $model The model instance.
     * @param string $filePath Relative path to the language file.
     * @param string $languageCode Language code.
     * @param array $keys Array of keys.
     * @param string $localeOperation Operation mode.
     * @param string|null $method Method being used.
     * @param array|string|null $affix Current affix.
     * @param mixed $oldAffix Old affix.
     * @return void
     * @throws Exception
     * @deprecated Use FileSynchronizer::updateLangEntries() instead.
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
        trigger_error(
            'LangKeyService::updateLangEntries() is deprecated. Use FileSynchronizer::updateLangEntries() instead.',
            E_USER_DEPRECATED
        );
        FileSynchronizer::updateLangEntries(
            $model,
            $filePath,
            $languageCode,
            $keys,
            $localeOperation,
            $method,
            $affix,
            $oldAffix
        );
    }
}
