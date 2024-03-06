<?php

namespace Prosetta\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Prosetta\Models\Queue;
use RuntimeException;

class LangKeyService {

    # Service Configuration
    protected static string|array|null $languageAffixAttribute = null;

    /**
     * Sets configuration parameters for the LangKeyService.
     *
     * @param array $config Configuration parameters.
     */
    public static function setConfig(array $config): void {
        static::$languageAffixAttribute = $config['affixAttribute'] ?? null;
    }

    /**
     * Language File Management
     * https://chat.openai.com/c/7c9f7eba-a97e-4a3e-ae2d-1dcb1b59e580
     * https://chat.openai.com/c/9e1ad4bb-d34b-49c0-86db-2568178bb5fb
     * https://chat.openai.com/c/74697819-0a38-48d8-a5ef-b0d8941c37f3
     * https://chat.openai.com/c/db59239a-e0ac-499c-b9ff-87cd3c16483d
     * https://chat.openai.com/c/eafa9d44-7597-4b00-9f08-5a54ddc25d08
     *
     * This function handles updating or deleting entries in a language file.
     * It supports both single and bulk operations by accepting keys and values as either
     * strings or arrays.
     *
     * TODO: Add support for updating multiple language files at once.
     * When new key is created, it should create keys in all language files that don't already have them.
     * If slug changes, it should update all existing keys, but retain their values (except for the current language).
     * An entry should be made into a changes database table for each key that is updated.
     *
     * TODO: Review Scaling Factors
     * TODO: Review Targeted Caching from this Conversation
     * https://chat.openai.com/c/b2fb7d12-5a0d-41a2-9f77-519fded0bb19
     *
     * @param mixed $model The model instance from which values are extracted for language entries.
     * @param string $filePath Path to the language file relative to the 'lang' directory.
     * @param string $languageCode Language code representing the target language file.
     * @param array $keys Array of keys to be managed. These keys can have an optional prefix.
     * @param string $localeOperation The operation mode for handling locales.
     * @param string|null $method The method being used to create/update the model.
     * @param string|array|null $affix Optional affix to be added to each key.
     * @param string|array|null $oldAffix Optional old affix, kept for processing other languages.
     * @param array $capturedData Optional array of captured data from other locales.
     * @return void
     * @throws Exception Thrown if there's an issue with file handling or if invalid key formats are detected.
     */
    public static function manageLanguageFileEntry(
        mixed        $model,
        string       $filePath,
        string       $languageCode,
        array        $keys,
        string       $localeOperation,
        string       $method = null,
        string|array $affix = null,
        string|array $oldAffix = null,
        array        $capturedData = []
    ): void {

        # Map Values from Model
        $values = self::mapToValues($keys, $model);

        # Validate Locale and Paths
        if (!preg_match('/^[a-zA-Z0-9-_\/]+$/', $filePath)) {
            throw new InvalidArgumentException("Invalid file path: $filePath");
        }

        if (!preg_match('/^[a-zA-Z0-9-_]+$/', $languageCode)) {
            throw new InvalidArgumentException("Invalid language code: $languageCode");
        }

        # Set the Full and Local Paths
        $localPath = "lang/$languageCode/$filePath.php";
        $fullPath = base_path($localPath);

        # Prefix Key Mapping and Pairing with Values
        $keyValuePairs = array_map(function ($key) use ($model, $affix, $oldAffix, $capturedData) {
            $affixedKey = $affix !== null ? self::applyAffixation($affix, $key) : $key;
            $originalAffixedKey = $oldAffix !== null ? self::applyAffixation($oldAffix, $key) : $key;

            $pair = [
                'isDirty' => $model->isDirty($key),
                'originalKey' => $key,
                'originalAffixedKey' => $originalAffixedKey,
                'affixedKey' => $affixedKey,
                'value' => $model->{$key},
                'oldValue' => $model->getOriginal($key)
            ];

            foreach ($capturedData as $lang => $data) {
                if (isset($data[$originalAffixedKey])) {
                    $pair[$lang] = $data[$originalAffixedKey];
                }
            }

            return $pair;
        }, $keys);

        # Check Visibility
        $fullUpdateNeeded = false;
        $visibilityCheck = self::checkVisibility($model, $filePath, $languageCode, $keyValuePairs);
        $fullUpdateNeeded = $visibilityCheck['fullUpdateNeeded'];
        if ($visibilityCheck['earlyExit']) {
            return;
        }

        # Full Update if Slug Changes
        foreach ($keyValuePairs as $keyValuePair) {
            if ($keyValuePair['originalKey'] !== $keyValuePair['originalAffixedKey']) {
                $fullUpdateNeeded = true;
                break;
            }
        }

        # Ensure File and Directory Exist and Create if Necessary
        self::ensureFileExists($fullPath, $localPath);

        # Load the Existing Language Data
        $langData = include($fullPath);

        # Process Each Key-Value Pair
        self::processKeyValuePairs($model, $keyValuePairs, $langData, $languageCode, $filePath, $fullUpdateNeeded, $localeOperation, $languageCode);

        # Save the Language Data to File
        self::writeToFile($fullPath, $langData);

        # Handle Other Locales
        if (in_array($localeOperation, ['all-keep', 'all-clear'])) {
            $activeLocales = config('prosetta.locales');
            foreach ($activeLocales as $otherLocale) {
                if ($otherLocale !== $languageCode) {
                    $otherLocaleFullPath = base_path("lang/$otherLocale/$filePath.php");
                    self::ensureFileExists($otherLocaleFullPath, "lang/$otherLocale/$filePath.php");

                    # Load the existing language data for the other locale
                    $otherLocaleLangData = file_exists($otherLocaleFullPath) ? include($otherLocaleFullPath) : [];

                    # Process the key-value pairs for the other locale
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

                    # Save the updated language data for the other locale
                    self::writeToFile($otherLocaleFullPath, $otherLocaleLangData);
                }
            }
        }
    }


    /**
     * Applies an affix to a given key based on the specified configuration.
     *
     * This method handles both string and array types for affixes. For string affixes,
     * it applies them as either a prefix or suffix, based on configuration. For array affixes,
     * it handles 'only', 'prefix', and 'suffix' types. It logs the affix for debugging purposes
     * and retrieves configuration for the affixation type and default.
     *
     * @param string|array $affix The affix to be applied. It can be a string or an array.
     *                            If it's an array, it expects two elements: the affix and its position ('prefix', 'suffix', or 'only').
     * @param string $key The key to which the affix is to be applied.
     * @return string The key with the affix applied. Returns the original key if the affix type is unsupported or if the affix is null.
     */
    private static function applyAffixation(string|array $affix, string $key): string {

        # Fetch Config
        $affixationType = config('prosetta.affixationType', '.');
        $affixationDefault = config('prosetta.affixationDefault', 'prefix');

        # Handling string affix
        if (is_string($affix)) {
            if ($affixationDefault === config('prosetta.prefix', 'prefix')) {
                return $affix . $affixationType . $key;
            } elseif ($affixationDefault === config('prosetta.suffix', 'suffix')) {
                return $key . $affixationType . $affix;
            }
        }

        # Placeholder for array affix handling
        if (is_array($affix) && count($affix) === 2) {
            [$firstAffix, $affixOrPos] = $affix;

            # Check for 'only' type
            if ($affixOrPos === config('prosetta.only', 'only')) {
                return $firstAffix;
            } elseif ($affixOrPos === config('prosetta.prefix', 'prefix')) {
                return $firstAffix . $affixationType . $key;
            } elseif ($affixOrPos === config('prosetta.suffix', 'suffix')) {
                return $key . $affixationType . $firstAffix;
            } else {
                # If Neither, Add Both
                return $firstAffix . $affixationType . $key . $affixationType . $affixOrPos;
            }
        }

        # Fallback for unsupported affix types
        return $key;
    }


    /**
     * Captures data from language files for given keys and locales.
     *
     * @param array $keys The keys to capture data for.
     * @param string $filePath The file path within the language files.
     * @param string $oldAffix The affix to be applied to the keys.
     * @return array An associative array of captured data.
     */
    private static function captureLocaleData(array $keys, string $filePath, string $oldAffix): array {
        $capturedData = [];
        $activeLocales = config('prosetta.locales');

        foreach ($activeLocales as $otherLocale) {
            $otherLocaleFullPath = base_path("lang/$otherLocale/$filePath.php");

            if (file_exists($otherLocaleFullPath)) {
                $otherLocaleLangData = include($otherLocaleFullPath);

                foreach ($keys as $key) {
                    $keyWithOldAffix = self::applyAffixation($oldAffix, $key);
                    if (isset($otherLocaleLangData[$keyWithOldAffix])) {
                        $capturedData[$otherLocale][$keyWithOldAffix] = $otherLocaleLangData[$keyWithOldAffix];
                    }
                }
            }
        }

        return $capturedData;
    }


    /**
     * Check the visibility of model attributes and update language keys accordingly.
     *
     * @param Model $model The model instance being processed.
     * @param string $filePath Path to the language file.
     * @param string $languageCode Language code.
     * @param array $keyValuePairs Array of key-value pairs.
     * @return array Associative array with 'fullUpdateNeeded' and 'earlyExit' flags.
     * @throws Exception
     */
    private static function checkVisibility(Model $model, string $filePath, string $languageCode, array $keyValuePairs): array {
        $fullUpdateNeeded = false;
        $earlyExit = false;
        $ignoreValues = config('prosetta.visibility.ignore_values', []);
        $visibilityColumns = config('prosetta.visibility.columns', []);

        foreach ($visibilityColumns as $column) {
            $columnValue = $model->{$column} ?? null;

            # Convert Boolean Values to Strings
            if (is_bool($columnValue)) {
                $columnValue = $columnValue ? 'public' : 'private';
            }

            # If Visibility is Off, Ensure Keys Aren't Visible
            if (in_array($columnValue, $ignoreValues, true)) {
                $reason = "Removed due to visibility settings.";
                $affixedKeysToRemove = array_map(fn($pair) => $pair['affixedKey'], $keyValuePairs);
                self::removeKeys($filePath, $languageCode, $affixedKeysToRemove, null, $reason);
                $earlyExit = true;
                break;
            }

            if ($model->isDirty($column)) {
                $fullUpdateNeeded = true;
            }
        }

        return ['fullUpdateNeeded' => $fullUpdateNeeded, 'earlyExit' => $earlyExit];
    }


    /**
     * Enqueue a translation task into the prosetta_queue.
     *
     * @param Model $model The model associated with the translation task.
     * @param string $method
     * @param array $keyValuePair Key-value pair containing data for translation.
     * @param string $baseLanguage The original language of the content.
     * @param string $languageCode The target language code for translation.
     * @param string $filePath Path to the file where translation is processed.
     *
     * @return void
     */
    private static function enqueueTranslationTask(Model $model, string $method, array $keyValuePair, string $baseLanguage, string $languageCode, string $filePath): void {
        # TODO: Implement rank calculation function here
        # TODO: Implement update() function here
        # $rank = self::calculateRank(...);

        # Obtain Model Details
        $modelDetails = self::getModelDetails($model, $keyValuePair['originalKey']);
        $oldValue = '';
        $oldBaseValue = !empty($keyValuePair['oldValue']) ? $keyValuePair['oldValue'] : '';

        if ($method === 'update') {
            # Default to Existing Pair, if Available (Full Update)
            $oldValue = !empty($keyValuePair[$languageCode]) ? $keyValuePair[$languageCode] : '';

            # If Not Set, Check Language File (Partial Update)
            if (empty($oldValue)) {
                $languageFilePath = base_path("lang/$languageCode/$filePath.php");
                if (file_exists($languageFilePath)) {
                    $languageData = include($languageFilePath);
                    $affixedKey = $keyValuePair['affixedKey'];
                    if (isset($languageData[$affixedKey])) {
                        $oldValue = $languageData[$affixedKey];
                    }
                }
            }
        }

        Queue::create([
            'key' => $keyValuePair['affixedKey'],
            'path' => $filePath,
            'lang' => $languageCode,
            'method' => $method,
            'model' => get_class($model),
            'model_description' => $modelDetails['description'],
            'context' => null,
            'base_lang' => $baseLanguage,
            'original_value' => $oldValue,
            'base_original_value' => $oldBaseValue,
            'base_value' => $keyValuePair['value'],
            'creator_id' => auth()->id() ?? null,
        ]);

        # Debug
        # DebugLog('info', '[SVC - LangKey] Enqueued a new translation task.', ['keyValuePair' => $keyValuePair], 'prosetta');
    }


    /**
     * Ensure that the directory and file for language data exist, creating them if necessary.
     *
     * @param string $fullPath Full path to the language file.
     * @param string $localPath Local path relative to the base path.
     * @return void
     * @throws Exception
     */
    private static function ensureFileExists(string $fullPath, string $localPath): void {
        try {
            $directoryPath = dirname($fullPath);
            $localDirectoryPath = dirname($localPath);
            if (!is_dir($directoryPath)) {
                mkdir($directoryPath, 0755, true);
                Log::channel(config('prosetta.logChannel', 'default'))->info('Directory created.', ['directory' => $localDirectoryPath]);
            }
            if (!file_exists($fullPath)) {
                file_put_contents($fullPath, "<?php\n\nreturn [];");
                Log::channel(config('prosetta.logChannel', 'default'))->info('Language file created.', ['file' => $localPath]);
            }
        } catch (Exception $e) {
            Log::channel(config('prosetta.logChannel', 'default'))->error('Error managing language file.', [
                'error' => $e->getMessage(),
                'path' => $localPath
            ]);
            throw $e;
        }
    }


    /**
     * Generate an array of prefixed keys. If the prefix is empty, keys remain unchanged.
     *
     * @param array $keys Array of base keys.
     * @param array|string|null $affix Affix to be applied to each key (optional). Can be a string or an array.
     * @return array Array of (prefixed/suffixed) keys.
     */
    public static function generatePrefixedKeys(array $keys, array|string $affix = null): array {
        return array_map(fn($key) => $affix !== null ? self::applyAffixation($affix, $key) : $key, $keys);
    }


    /**
     * Get details of the model.
     *
     * @param Model $model The model instance.
     * @param string $key The key for which details are needed.
     * @return array Array of model details.
     */
    private static function getModelDetails(Model $model, string $key): array {
        $modelDetails = [];

        if (property_exists($model, 'modelDescription')) {
            if (is_array($model->modelDescription)) {
                $modelDetails['description'] = $model->modelDescription[$key] ?? null;
            } else {
                $modelDetails['description'] = $model->modelDescription;
            }
        } else {
            # Doesn't Exist
            $modelDetails['description'] = null;
        }

        return $modelDetails;
    }


    /**
     * Checks if any affix attribute has changed.
     *
     * @param mixed $model The model instance being checked.
     * @return bool
     */
    private static function hasAffixChanged(mixed $model): bool {
        $affixAttributes = is_array(static::$languageAffixAttribute)
            ? static::$languageAffixAttribute
            : [static::$languageAffixAttribute];

        foreach ($affixAttributes as $attr) {
            if ($model->isDirty($attr)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Map keys to their corresponding values from the given model.
     *
     * @param array $keys Array of keys to be mapped.
     * @param $model Model instance to fetch values from.
     * @return array Mapped values.
     */
    public static function mapToValues(array $keys, Model $model): array {
        return array_map(function ($key) use ($model) {
            return $model->{$key};
        }, $keys);
    }


    /**
     * Update a single key-value pair in the language data array.
     *
     * @param string $key Key of the entry.
     * @param string $value Value for the entry.
     * @param array &$langData Reference to the language data array.
     * @param string $languageCode Language code.
     * @param string $filePath Path to the language file.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public static function processKeyValue(
        string $key,
        string $value,
        array  &$langData,
        string $languageCode,
        string $filePath
    ): void {

        # MarkdownSanitizer
        $markdownSanitizer = new MarkdownSanitizer();

        # Validate Key Format
        if (!preg_match('/' . config('prosetta.key_pattern', '^[a-zA-Z0-9-._]+$') . '/', $key)) {
            throw new InvalidArgumentException("Invalid key format: $key");
        }

        # If processing non-base languages, handle values based on localeOperation
        $value = $markdownSanitizer->sanitize($value);

        # Update the Language Data
        $langData[$key] = $value;
        ksort($langData);

        # Input Log
        Log::channel(config('prosetta.logChannel', 'default'))->info('Language file key updated.', [
            'language_code' => $languageCode,
            'key' => $key,
            'value' => $value,
            'file_path' => $filePath
        ]);
    }


    private static function processKeyValuePairs(
        Model   $model,
        array   $keyValuePairs,
        array   &$langData,
        string  $languageCode,
        string  $filePath,
        bool    $fullUpdateNeeded,
        ?string $localeOperation = null,
        ?string $baseLanguage = null,
        ?string $method = null
    ): void {

        if ($fullUpdateNeeded) {
            foreach ($keyValuePairs as $keyValuePair) {
                $valueToSet = $languageCode === $baseLanguage ? $keyValuePair['value'] :
                    ($keyValuePair['isDirty'] === true ? ($localeOperation === 'all-clear' ? '' : ($keyValuePair[$languageCode] ?? '')) :
                        ($keyValuePair[$languageCode] ?? ''));

                # Process Keys
                self::processKeyValue($keyValuePair['affixedKey'], $valueToSet, $langData, $languageCode, $filePath);
                $baseLanguage !== $languageCode && self::enqueueTranslationTask($model, $method, $keyValuePair, $baseLanguage, $languageCode, $filePath);
            }
        } else {
            foreach ($keyValuePairs as $keyValuePair) {
                if ($keyValuePair['isDirty']) {
                    $valueToSet = ($languageCode === $baseLanguage) ? $keyValuePair['value'] : ($localeOperation === 'all-clear' ? '' : ($method === 'create' ? '' : null));
                    if ($valueToSet !== null) {
                        self::processKeyValue($keyValuePair['affixedKey'], $valueToSet, $langData, $languageCode, $filePath);
                    }
                    $baseLanguage !== $languageCode && self::enqueueTranslationTask($model, $method, $keyValuePair, $baseLanguage, $languageCode, $filePath);
                }
            }
        }
    }


    /**
     * Remove specified keys from a language file.
     *
     * @param string $filePath Relative path to the language file.
     * @param string $languageCode Language code for the file.
     * @param array|string $keys Key(s) to be removed.
     * @param array|string|null $affix Optional prefix or suffix to be applied to each key.
     * @param string|null $reason Reason for the removal, defaults to "Entry Deleted" if null.
     * @param string $localeOperation The operation mode for handling locales ('all-keep', 'all-clear').
     * @return void
     * @throws Exception
     */
    public static function removeKeys(
        string            $filePath,
        string            $languageCode,
        array|string      $keys,
        array|string|null $affix = null,
        ?string           $reason = null,
        string            $localeOperation = 'all-keep'
    ): void {
        $reason = $reason ?? "Entry Deleted";

        # Apply prefix to keys if provided
        $keysToRemove = self::generatePrefixedKeys(is_array($keys) ? $keys : [$keys], $affix);

        # Remove from the Queue
        foreach ($keysToRemove as $affixedKey) {
            Queue::where('key', $affixedKey)
                ->where('path', $filePath)
                ->delete();

            Log::channel(config('prosetta.logChannel', 'default'))->info('Queue entry removed.', [
                'key' => $affixedKey,
                'path' => $filePath
            ]);
        }

        # Construct the Full Path
        $localPath = "lang/$languageCode/$filePath.php";
        $fullPath = base_path($localPath);

        # Ensure File Exists
        if (!file_exists($fullPath)) return;

        # Load the Existing Language Data
        $langData = include($fullPath);

        # Flag Check
        $keyRemoved = false;

        # Remove the Keys
        foreach ($keysToRemove as $k) {
            if (isset($langData[$k])) {
                unset($langData[$k]);
                $keyRemoved = true;
            }
        }

        # If No Keys, Return Before Log
        if (!$keyRemoved) return;

        # Save File and Log
        self::writeToFile($fullPath, $langData);

        # Handle Other Locales
        # TODO: Consider localeOperation Logic, such as keeping other keys
        if (in_array($localeOperation, ['all-keep', 'all-clear'])) {
            $activeLocales = config('prosetta.locales');
            foreach ($activeLocales as $otherLocale) {
                if ($otherLocale !== $languageCode) {
                    $otherLocaleFullPath = base_path("lang/$otherLocale/$filePath.php");
                    if (file_exists($otherLocaleFullPath)) {
                        $otherLocaleLangData = include($otherLocaleFullPath);
                        foreach ($keysToRemove as $k) {
                            if (isset($otherLocaleLangData[$k])) {
                                unset($otherLocaleLangData[$k]);
                            }
                        }
                        self::writeToFile($otherLocaleFullPath, $otherLocaleLangData);
                    }
                }
            }
        }

        # Input Log
        Log::channel(config('prosetta.logChannel', 'default'))->info('Language file updated - keys removed.', [
            'path' => $localPath,
            'keys' => $keysToRemove,
            'reason' => $reason
        ]);
    }


    /**
     * Update keys in the language file when a specific field changes.
     *
     * @param mixed $model The model instance being updated.
     * @param string $filePath Relative path to the language file.
     * @param string $languageCode Language code for the file.
     * @param array $keys Array of keys associated with the model.
     * @param array|string|null $affix Current affix for the keys (usually based on the field value).
     * @param string $localeOperation The operation mode for handling locales.
     * @param string|null $method The method being used to create/update the model.
     * @param array|string|null $oldAffix Old affix for the keys before the update (optional).
     * @return void
     * @throws Exception
     */
    public static function updateLangEntries(
        mixed        $model,
        string       $filePath,
        string       $languageCode,
        array        $keys,
        string       $localeOperation,
        string       $method = null,
        array|string $affix = null,
        mixed        $oldAffix = null
    ): void {

        # Remove Keys if Affix Changed
        if (self::hasAffixChanged($model)) {
            $reason = "Affix Updated";

            # Initialize an array to capture existing data from other locales
            $capturedData = [];

            # Capture Old Data
            if (in_array($localeOperation, ['all-keep', 'all-clear'])) {
                $capturedData = self::captureLocaleData($keys, $filePath, $oldAffix);

                # Log captured data (Save for Testing)
                # Log::channel(config('prosetta.logChannel', 'default'))->info('Captured old data.', ['capturedData' => $capturedData]);
            }

            self::removeKeys($filePath, $languageCode, $keys, $oldAffix, $reason, $localeOperation);
        }

        # Update the Language File for the current locale
        if (isset($oldAffix) && isset($capturedData)) {
            LangKeyService::manageLanguageFileEntry($model, $filePath, $languageCode, $keys, $localeOperation, $method, $affix, $oldAffix, $capturedData);
        } else {
            LangKeyService::manageLanguageFileEntry($model, $filePath, $languageCode, $keys, $localeOperation, $method, $affix);
        }
    }


    /**
     * Writes the given language data array to a file at the specified path.
     *
     * @param string $filePath Path to the language file.
     * @param array $langData Language data to be written to the file.
     * @return void
     * @throws Exception If the file cannot be written.
     */
    private static function writeToFile(string $filePath, array $langData): void {
        $backupFilePath = $filePath . '.bak';
        $output = "<?php\n\nreturn " . var_export($langData, true) . ";\n";

        try {
            # Create a Backup
            if (file_exists($filePath)) {
                if (!copy($filePath, $backupFilePath)) {
                    throw new RuntimeException("Failed to create backup file: $backupFilePath");
                }
            }

            # Attempt to Write Content
            if (file_put_contents($filePath, $output) === false) {
                throw new RuntimeException("Failed to write to file: $filePath");
            }

            # Remove Backup if Write Successful
            if (file_exists($backupFilePath)) {
                unlink($backupFilePath);
            }

            # Flush the Cache
            # TODO: Cache Logic Review
            $cacheKey = 'lang_file_' . md5($filePath);
            Cache::forget($cacheKey);

        } catch (RuntimeException $e) {
            # Attempt to Restore from Backup
            if (file_exists($backupFilePath)) {
                if (!rename($backupFilePath, $filePath)) {
                    # TODO: Additional Error Handling
                    Log::channel(config('prosetta.logChannel', 'default'))->error("Failed to restore from backup. Manual intervention required. File: $filePath");
                }
            }
            throw $e;
        }
    }
}
