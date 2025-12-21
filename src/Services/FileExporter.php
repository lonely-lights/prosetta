<?php

namespace LonelyLights\Prosetta\Services;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * File Exporter Service
 *
 * Handles exporting translation data to language files including
 * file creation, writing, and backup management.
 *
 * @package LonelyLights\Prosetta\Services
 */
class FileExporter {
    /**
     * Ensure that the directory and file for language data exist.
     *
     * Creates the directory structure and empty language file if they don't exist.
     *
     * @param string $fullPath Full path to the language file.
     * @param string $localPath Local path relative to the base path (for logging).
     * @return void
     * @throws Exception If file or directory cannot be created.
     */
    public static function ensureFileExists(string $fullPath, string $localPath): void {
        try {
            $directoryPath = dirname($fullPath);
            $localDirectoryPath = dirname($localPath);

            if (!is_dir($directoryPath)) {
                mkdir($directoryPath, 0755, true);
                Log::channel(config('prosetta.logChannel', 'default'))->info('Directory created.', [
                    'directory' => $localDirectoryPath,
                ]);
            }

            if (!file_exists($fullPath)) {
                file_put_contents($fullPath, "<?php\n\nreturn [];");
                Log::channel(config('prosetta.logChannel', 'default'))->info('Language file created.', [
                    'file' => $localPath,
                ]);
            }
        } catch (Exception $e) {
            Log::channel(config('prosetta.logChannel', 'default'))->error('Error managing language file.', [
                'error' => $e->getMessage(),
                'path' => $localPath,
            ]);
            throw $e;
        }
    }

    /**
     * Writes the given language data array to a file.
     *
     * Creates a backup before writing and restores it if writing fails.
     *
     * @param string $filePath Path to the language file.
     * @param array $langData Language data to be written.
     * @return void
     * @throws RuntimeException If the file cannot be written.
     */
    public static function writeToFile(string $filePath, array $langData): void {
        $backupFilePath = $filePath . '.bak';
        $output = "<?php\n\nreturn " . var_export($langData, true) . ";\n";

        try {
            // Create a backup
            if (file_exists($filePath)) {
                if (!copy($filePath, $backupFilePath)) {
                    throw new RuntimeException("Failed to create backup file: $backupFilePath");
                }
            }

            // Attempt to write content
            if (file_put_contents($filePath, $output) === false) {
                throw new RuntimeException("Failed to write to file: $filePath");
            }

            // Remove backup if write was successful
            if (file_exists($backupFilePath)) {
                unlink($backupFilePath);
            }

            // Flush the cache for this file
            $cacheKey = 'lang_file_' . md5($filePath);
            Cache::forget($cacheKey);

        } catch (RuntimeException $e) {
            // Attempt to restore from backup
            if (file_exists($backupFilePath)) {
                if (!rename($backupFilePath, $filePath)) {
                    Log::channel(config('prosetta.logChannel', 'default'))->error(
                        "Failed to restore from backup. Manual intervention required. File: $filePath"
                    );
                }
            }
            throw $e;
        }
    }

    /**
     * Process and validate a single key-value pair for the language data array.
     *
     * Validates key format and sanitizes the value before adding to language data.
     *
     * @param string $key Key of the entry.
     * @param string $value Value for the entry.
     * @param array &$langData Reference to the language data array.
     * @param string $languageCode Language code.
     * @param string $filePath Path to the language file.
     * @return void
     * @throws InvalidArgumentException If key format is invalid.
     */
    public static function processKeyValue(
        string $key,
        string $value,
        array  &$langData,
        string $languageCode,
        string $filePath
    ): void {
        $markdownSanitizer = new MarkdownSanitizer();

        // Validate key format
        $keyPattern = config('prosetta.key_pattern', '^[a-zA-Z0-9-._]+$');
        if (!preg_match('/' . $keyPattern . '/', $key)) {
            throw new InvalidArgumentException("Invalid key format: $key");
        }

        // Sanitize the value
        $value = $markdownSanitizer->sanitize($value);

        // Update the language data
        $langData[$key] = $value;
        ksort($langData);

        Log::channel(config('prosetta.logChannel', 'default'))->info('Language file key updated.', [
            'language_code' => $languageCode,
            'key' => $key,
            'value' => $value,
            'file_path' => $filePath,
        ]);
    }

    /**
     * Generate a formatted language file with metadata header.
     *
     * This method will be used when exporting database translations to files,
     * adding commented metadata at the top of each file.
     *
     * @param array $langData The language data array.
     * @param string|null $title Optional file title for the header.
     * @param string|null $description Optional file description for the header.
     * @return string The formatted PHP file content.
     */
    public static function formatWithHeader(
        array   $langData,
        ?string $title = null,
        ?string $description = null
    ): string {
        $header = "<?php\n\n";

        if ($title !== null || $description !== null) {
            $header .= "/**\n";
            if ($title !== null) {
                $header .= " * $title\n";
            }
            if ($description !== null) {
                $header .= " *\n";
                // Wrap description at 80 chars
                $lines = wordwrap($description, 76, "\n", true);
                foreach (explode("\n", $lines) as $line) {
                    $header .= " * $line\n";
                }
            }
            $header .= " *\n";
            $header .= " * Generated by Prosetta Translation Manager\n";
            $header .= " */\n\n";
        }

        $header .= "return " . var_export($langData, true) . ";\n";

        return $header;
    }
}
