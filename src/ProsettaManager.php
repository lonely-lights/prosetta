<?php

namespace LonelyLights\Prosetta;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Events\TranslationExported;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Services\FileExporter;
use LonelyLights\Prosetta\Services\FileScanner;
use Throwable;

/**
 * Prosetta Manager
 *
 * The main entry point for the Prosetta translation system.
 * This class is accessible via the Prosetta facade.
 *
 * @package LonelyLights\Prosetta
 */
class ProsettaManager {
    /**
     * @var FileScanner
     */
    protected FileScanner $scanner;

    /**
     * @var FileExporter
     */
    protected FileExporter $exporter;

    /**
     * Create a new ProsettaManager instance.
     *
     * @param FileScanner $scanner
     */
    public function __construct(FileScanner $scanner) {
        $this->scanner = $scanner;
    }

    /**
     * Sync translation files from disk to database.
     *
     * @param string $locale Locale to sync
     * @return array Sync report
     * @throws Throwable
     */
    public function sync(string $locale): array {
        $files = $this->scanner->scan($locale);

        $report = [
            'locale' => $locale,
            'new_files' => [],
            'new_keys' => [],
            'updated_keys' => [],
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
            foreach ($files as $fileInfo) {
                $result = $this->syncFile($fileInfo, $locale);
                $report['new_files'] = array_merge($report['new_files'], $result['new_files']);
                $report['new_keys'] = array_merge($report['new_keys'], $result['new_keys']);
                $report['updated_keys'] = array_merge($report['updated_keys'], $result['updated_keys']);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            $report['errors'][] = $e->getMessage();
        }

        return $report;
    }

    /**
     * Sync all locales.
     *
     * @return array Combined sync report
     * @throws Throwable
     */
    public function syncAll(): array {
        $locales = $this->scanner->getAvailableLocales();
        $reports = [];

        foreach ($locales as $locale) {
            $reports[$locale] = $this->sync($locale);
        }

        return $reports;
    }

    /**
     * Sync a single file's contents.
     *
     * @param array $fileInfo
     * @param string $locale
     * @return array
     */
    protected function syncFile(array $fileInfo, string $locale): array {
        $result = [
            'new_files' => [],
            'new_keys' => [],
            'updated_keys' => [],
        ];

        // Find or create the file record
        /** @var TranslationFile $file */
        $file = TranslationFile::firstOrCreate(
            ['path' => $fileInfo['path']],
            [
                'name' => $fileInfo['metadata']['name'] ?? ucfirst(basename($fileInfo['path'])),
                'description' => $fileInfo['metadata']['description'] ?? null,
                'category' => $fileInfo['metadata']['category'] ?? null,
            ]
        );

        if ($file->wasRecentlyCreated) {
            $result['new_files'][] = $fileInfo['path'];
        }

        // Sync each key
        foreach ($fileInfo['keys'] as $key => $value) {
            $keyResult = $this->syncKey($file, $key, $value, $locale);
            if ($keyResult['is_new']) {
                $result['new_keys'][] = $file->path . '.' . $key;
            } elseif ($keyResult['was_updated']) {
                $result['updated_keys'][] = $file->path . '.' . $key;
            }
        }

        $file->markAsSynced();

        return $result;
    }

    /**
     * Sync a single translation key.
     *
     * @param TranslationFile $file
     * @param string $key
     * @param string $value
     * @param string $locale
     * @return array
     */
    protected function syncKey(TranslationFile $file, string $key, string $value, string $locale): array {
        // Find or create the key
        /** @var TranslationKey $translationKey */
        $translationKey = TranslationKey::firstOrCreate(
            ['file_id' => $file->id, 'key' => $key],
            ['description' => null]
        );

        $isNewKey = $translationKey->wasRecentlyCreated;

        // Find or create the translation
        $translation = Translation::where('key_id', $translationKey->id)
            ->where('locale', $locale)
            ->first();

        $wasUpdated = false;

        if ($translation) {
            // Update if value changed
            if ($translation->value !== $value) {
                $translation->update([
                    'value' => $value,
                    'source' => Translation::SOURCE_IMPORTED,
                ]);
                $wasUpdated = true;
            }
        } else {
            // Create new translation
            Translation::create([
                'key_id' => $translationKey->id,
                'locale' => $locale,
                'value' => $value,
                'status' => Translation::STATUS_APPROVED,
                'source' => Translation::SOURCE_IMPORTED,
            ]);
        }

        return [
            'is_new' => $isNewKey,
            'was_updated' => $wasUpdated,
        ];
    }

    /**
     * Get all translation files.
     *
     * @return EloquentCollection<int, TranslationFile>
     */
    public function files(): EloquentCollection {
        return TranslationFile::orderBy('path')->get();
    }

    /**
     * Get a specific translation file.
     *
     * @param string $path
     * @return TranslationFile|null
     */
    public function file(string $path): ?TranslationFile {
        return TranslationFile::where('path', $path)->first();
    }

    /**
     * Create a new translation file.
     *
     * @api
     * @param string $path
     * @param array $attributes
     * @return TranslationFile
     */
    public function createFile(string $path, array $attributes = []): TranslationFile {
        return TranslationFile::create(array_merge(
            ['path' => $path],
            $attributes
        ));
    }

    /**
     * Get all keys for a file.
     *
     * @param string $filePath
     * @return EloquentCollection<int, TranslationKey>|Collection<int, TranslationKey>
     */
    public function keys(string $filePath): EloquentCollection|Collection {
        $file = $this->file($filePath);

        if (!$file) {
            return collect();
        }

        return $file->keys()->orderBy('key')->get();
    }

    /**
     * Set a translation value.
     *
     * @param string $fullKey Full key in format 'file.key' or 'file.nested.key'
     * @param string $value
     * @param string $locale
     * @param string $source
     * @return Translation|null
     */
    public function set(string $fullKey, string $value, string $locale, string $source = 'manual'): ?Translation {
        // Parse the full key into file path and key
        $parts = explode('.', $fullKey, 2);

        if (count($parts) < 2) {
            return null;
        }

        [$filePath, $key] = $parts;

        // Find or create the file
        /** @var TranslationFile $file */
        $file = TranslationFile::firstOrCreate(
            ['path' => $filePath],
            ['name' => ucfirst($filePath)]
        );

        // Find or create the key
        /** @var TranslationKey $translationKey */
        $translationKey = TranslationKey::firstOrCreate(
            ['file_id' => $file->id, 'key' => $key]
        );

        // Set the translation
        return $translationKey->setTranslation($locale, $value, $source);
    }

    /**
     * Get a translation value.
     *
     * @param string $fullKey
     * @param string $locale
     * @param string|null $fallback
     * @return string|null
     */
    public function get(string $fullKey, string $locale, ?string $fallback = null): ?string {
        $parts = explode('.', $fullKey, 2);

        if (count($parts) < 2) {
            return $fallback;
        }

        [$filePath, $key] = $parts;

        $file = TranslationFile::where('path', $filePath)->first();

        if (!$file) {
            return $fallback;
        }

        $translationKey = TranslationKey::where('file_id', $file->id)
            ->where('key', $key)
            ->first();

        if (!$translationKey) {
            return $fallback;
        }

        return $translationKey->getValue($locale, $fallback);
    }

    /**
     * Delete a translation key.
     *
     * @param string $fullKey
     * @return bool
     */
    public function delete(string $fullKey): bool {
        $parts = explode('.', $fullKey, 2);

        if (count($parts) < 2) {
            return false;
        }

        [$filePath, $key] = $parts;

        $file = TranslationFile::where('path', $filePath)->first();

        if (!$file) {
            return false;
        }

        $translationKey = TranslationKey::where('file_id', $file->id)
            ->where('key', $key)
            ->first();

        if (!$translationKey) {
            return false;
        }

        return $translationKey->delete();
    }

    /**
     * Export a file to disk.
     *
     * @param string $locale
     * @param string $filePath
     * @return bool
     * @throws Exception
     */
    public function export(string $locale, string $filePath): bool {
        $file = $this->file($filePath);

        if (!$file) {
            return false;
        }

        // Get all translations for this file and locale
        $translations = Translation::whereHas('key', fn($q) => $q->where('file_id', $file->id))
            ->where('locale', $locale)
            ->with('key')
            ->get();

        // Build the language array
        $langData = [];
        /** @var Translation $translation */
        foreach ($translations as $translation) {
            $this->setNestedValue($langData, $translation->key->key, $translation->value);
        }

        // Generate file content with header
        $content = FileExporter::formatWithHeader(
            $langData,
            $file->name,
            $file->description
        );

        // Write to disk
        $fullPath = $file->getFullPath($locale);
        FileExporter::ensureFileExists($fullPath, "lang/$locale/$file->path.php");

        $result = file_put_contents($fullPath, $content) !== false;

        if ($result) {
            $file->markAsExported();
            TranslationExported::dispatch($file, $locale, $fullPath, count($translations));
        }

        return $result;
    }

    /**
     * Export all files for a locale.
     *
     * @param string $locale
     * @return array Results for each file
     * @throws Exception
     */
    public function exportAll(string $locale): array {
        $files = $this->files();
        $results = [];

        foreach ($files as $file) {
            $results[$file->path] = $this->export($locale, $file->path);
        }

        return $results;
    }

    /**
     * Get translation statistics for a locale.
     *
     * @param string $locale
     * @return array
     */
    public function statistics(string $locale): array {
        $totalKeys = TranslationKey::count();

        $translated = Translation::where('locale', $locale)->count();

        $needsReview = Translation::where('locale', $locale)
            ->where('status', Translation::STATUS_NEEDS_REVIEW)
            ->count();

        $approved = Translation::where('locale', $locale)
            ->where('status', Translation::STATUS_APPROVED)
            ->count();

        $missing = $totalKeys - $translated;

        return [
            'locale' => $locale,
            'total' => $totalKeys,
            'translated' => $translated,
            'missing' => $missing,
            'needs_review' => $needsReview,
            'approved' => $approved,
            'completion_percentage' => $totalKeys > 0 ? round(($translated / $totalKeys) * 100, 1) : 0,
        ];
    }

    /**
     * Get all active locales.
     *
     * @return Collection
     */
    public function locales(): Collection {
        return Locale::active()->ordered()->get();
    }

    /**
     * Get the default locale.
     *
     * @return Locale|null
     */
    public function defaultLocale(): ?Locale {
        return Locale::getDefault();
    }

    /**
     * Set a nested array value using dot notation.
     *
     * @param array &$array
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function setNestedValue(array &$array, string $key, mixed $value): void {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }
}
