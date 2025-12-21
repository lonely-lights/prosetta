<?php

namespace LonelyLights\Prosetta\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * File Scanner Service
 *
 * Scans the lang/ directory to discover and analyze translation files.
 *
 * @package LonelyLights\Prosetta\Services
 */
class FileScanner
{
    /**
     * Scan a locale directory and return information about all translation files.
     *
     * @param string $locale The locale to scan (e.g., 'en')
     * @return array Array of file information
     */
    public function scan(string $locale): array
    {
        $langPath = lang_path($locale);

        if (!is_dir($langPath)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($langPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $fileInfo = $this->analyzeFile($file->getRealPath(), $langPath, $locale);
                if ($fileInfo !== null) {
                    $files[] = $fileInfo;
                }
            }
        }

        return $files;
    }

    /**
     * Analyze a single translation file.
     *
     * @param string $fullPath Full path to the file
     * @param string $langPath Base lang path
     * @param string $locale Locale code
     * @return array|null File information or null if invalid
     */
    protected function analyzeFile(string $fullPath, string $langPath, string $locale): ?array
    {
        try {
            // Calculate relative path without extension
            $relativePath = str_replace(
                [$langPath . DIRECTORY_SEPARATOR, '.php'],
                ['', ''],
                $fullPath
            );

            // Normalize path separators
            $relativePath = str_replace('\\', '/', $relativePath);

            // Load and parse the file
            $content = $this->loadFile($fullPath);

            if ($content === null) {
                return null;
            }

            // Analyze the structure
            $keys = $this->extractKeys($content);
            $metadata = $this->extractMetadata($fullPath);

            return [
                'path' => $relativePath,
                'full_path' => $fullPath,
                'locale' => $locale,
                'key_count' => count($keys),
                'keys' => $keys,
                'nested_depth' => $this->calculateNestedDepth($content),
                'has_metadata' => !empty($metadata),
                'metadata' => $metadata,
                'file_size' => filesize($fullPath),
                'last_modified' => filemtime($fullPath),
            ];
        } catch (Exception $e) {
            Log::channel(config('prosetta.logChannel', 'default'))->warning(
                'Failed to analyze translation file',
                ['path' => $fullPath, 'error' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Safely load a PHP translation file.
     *
     * @param string $fullPath
     * @return array|null
     */
    protected function loadFile(string $fullPath): ?array
    {
        if (!file_exists($fullPath)) {
            return null;
        }

        try {
            $content = include $fullPath;

            if (!is_array($content)) {
                return null;
            }

            return $content;
        } catch (Exception $e) {
            Log::channel(config('prosetta.logChannel', 'default'))->warning(
                'Failed to load translation file',
                ['path' => $fullPath, 'error' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Extract all keys from a translation array (including nested keys).
     *
     * @param array $content
     * @param string $prefix
     * @return array Flat array of dot-notation keys with their values
     */
    public function extractKeys(array $content, string $prefix = ''): array
    {
        $keys = [];

        foreach ($content as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                // Recursively extract nested keys
                $nestedKeys = $this->extractKeys($value, $fullKey);
                $keys = array_merge($keys, $nestedKeys);
            } else {
                $keys[$fullKey] = $value;
            }
        }

        return $keys;
    }

    /**
     * Calculate the maximum nesting depth of the array.
     *
     * @param array $content
     * @param int $currentDepth
     * @return int
     */
    protected function calculateNestedDepth(array $content, int $currentDepth = 0): int
    {
        $maxDepth = $currentDepth;

        foreach ($content as $value) {
            if (is_array($value)) {
                $depth = $this->calculateNestedDepth($value, $currentDepth + 1);
                $maxDepth = max($maxDepth, $depth);
            }
        }

        return $maxDepth;
    }

    /**
     * Extract Prosetta metadata from file comments.
     *
     * @param string $fullPath
     * @return array
     */
    protected function extractMetadata(string $fullPath): array
    {
        $content = file_get_contents($fullPath);

        // Look for the Prosetta header block
        $metadata = [];

        // Match title from: | Title Here
        if (preg_match('/\|\s*\n\|\s*(.+?)\s*\n\|[-]+/', $content, $matches)) {
            $metadata['name'] = trim($matches[1]);
        }

        // Match category from: | Category: Something
        if (preg_match('/Category:\s*(.+?)(?:\n|\*)/', $content, $matches)) {
            $metadata['category'] = trim($matches[1]);
        }

        // Match description block
        if (preg_match('/\|[-]+\|\s*\n\|\s*\n\|\s*(.+?)(?=\|\s*\n\|\s*Category:|\|\s*\n\|\s*Last)/s', $content, $matches)) {
            $description = trim($matches[1]);
            // Clean up the description
            $description = preg_replace('/^\|\s*/m', '', $description);
            $metadata['description'] = trim($description);
        }

        return $metadata;
    }

    /**
     * Get all available locales by scanning the lang directory.
     *
     * @return array Array of locale codes
     */
    public function getAvailableLocales(): array
    {
        $langPath = lang_path();

        if (!is_dir($langPath)) {
            return [];
        }

        $locales = [];

        foreach (scandir($langPath) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $langPath . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                // Validate locale format (e.g., 'en', 'en_US', 'zh-Hans')
                if (preg_match('/^[a-z]{2}(_[A-Z]{2})?(-[A-Za-z]+)?$/', $item)) {
                    $locales[] = $item;
                }
            }
        }

        return $locales;
    }

    /**
     * Compare two locales and find differences.
     *
     * @param string $baseLocale
     * @param string $compareLocale
     * @return array
     */
    public function compareLocales(string $baseLocale, string $compareLocale): array
    {
        $baseFiles = $this->scan($baseLocale);
        $compareFiles = $this->scan($compareLocale);

        $baseFileMap = collect($baseFiles)->keyBy('path');
        $compareFileMap = collect($compareFiles)->keyBy('path');

        $results = [
            'missing_files' => [],
            'extra_files' => [],
            'missing_keys' => [],
            'extra_keys' => [],
        ];

        // Find files in base but not in compare
        foreach ($baseFileMap as $path => $fileInfo) {
            if (!isset($compareFileMap[$path])) {
                $results['missing_files'][] = $path;
                continue;
            }

            // Compare keys
            $baseKeys = array_keys($fileInfo['keys'] ?? []);
            $compareKeys = array_keys($compareFileMap[$path]['keys'] ?? []);

            $missing = array_diff($baseKeys, $compareKeys);
            $extra = array_diff($compareKeys, $baseKeys);

            if (!empty($missing)) {
                $results['missing_keys'][$path] = $missing;
            }

            if (!empty($extra)) {
                $results['extra_keys'][$path] = $extra;
            }
        }

        // Find files in compare but not in base
        foreach ($compareFileMap as $path => $fileInfo) {
            if (!isset($baseFileMap[$path])) {
                $results['extra_files'][] = $path;
            }
        }

        return $results;
    }

    /**
     * Get statistics for a locale.
     *
     * @param string $locale
     * @return array
     */
    public function getStatistics(string $locale): array
    {
        $files = $this->scan($locale);

        $totalKeys = 0;
        $totalFiles = count($files);
        $categories = [];

        foreach ($files as $file) {
            $totalKeys += $file['key_count'];

            if (!empty($file['metadata']['category'])) {
                $category = $file['metadata']['category'];
                $categories[$category] = ($categories[$category] ?? 0) + 1;
            }
        }

        return [
            'locale' => $locale,
            'total_files' => $totalFiles,
            'total_keys' => $totalKeys,
            'categories' => $categories,
            'files' => $files,
        ];
    }
}
