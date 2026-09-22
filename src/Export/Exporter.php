<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Discovery\LangReader;
use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Discovery\RootDiscovery;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\ExportCompleted;
use LonelyLights\Prosetta\Exceptions\LangFileException;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\PathFilter;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Writes target-locale files for root and module lang folders in the source
 * file's key order. Never writes the source locale, never deletes a file,
 * never writes an excluded path, and never rewrites an unchanged file. A
 * target file holding anything Prosetta did not write itself (a hand edit
 * not yet synced, a file never synced, a key the source lacks) is left
 * alone and reported as a conflict unless the export is forced.
 */
final class Exporter {
    public function __construct(
        private readonly RootDiscovery $discovery,
        private readonly LangReader $reader,
        private readonly LocaleSource $locales,
        private readonly PathFilter $filter,
        private readonly PhpArrayWriter $php,
        private readonly JsonWriter $json,
        private readonly Filesystem $files,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     */
    public function export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, bool $force = false): ExportReport {
        $includeDrafts ??= (bool) config('prosetta.export.include_drafts', false);
        $report = new ExportReport($dryRun);
        $source = $this->locales->source();
        $targets = array_values(array_filter(
            array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets()),
            fn (string $code) => $code !== $source && ($locales === [] || in_array($code, $locales, true)),
        ));
        $fileModel = Settings::model('file');

        foreach ($this->discovery->roots($namespaces === [] ? null : $namespaces) as $root) {
            foreach ($fileModel::query()->where('namespace', $root->namespace)->orderBy('group')->get() as $file) {
                /** @var TranslationFile $file */
                $order = array_map('strval', array_keys($this->reader->read($root, $source, $file->group, $file->format)));
                $keys = $file->keys()->whereNull('obsolete_at')->get()->keyBy(fn (TranslationKey $key) => $key->key);

                foreach ($targets as $locale) {
                    $this->exportFile($root, $file, $order, $keys, $source, $locale, $includeDrafts, $force, $report);
                }
            }
        }

        if (! $dryRun) {
            $this->events->dispatch(new ExportCompleted($report));
        }

        return $report;
    }

    /**
     * @param list<string> $order
     * @param Collection<string, TranslationKey> $keys
     */
    private function exportFile(LangRoot $root, TranslationFile $file, array $order, Collection $keys, string $source, string $locale, bool $includeDrafts, bool $force, ExportReport $report): void {
        $path = $this->reader->path($root, $locale, $file->group, $file->format);

        if ($this->filter->excluded($path)) {
            $report->refused[] = $path;

            return;
        }

        if (! $force && ($conflicts = $this->conflicts($root, $file, $locale)) !== []) {
            $report->conflicts[$path] = $conflicts;

            return;
        }

        $translationModel = Settings::model('translation');
        $translations = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $keys->modelKeys())->get()->keyBy('key_id');
        $values = [];
        $written = [];

        foreach ($order as $key) {
            $model = $keys->get($key);
            $translation = $model === null ? null : $translations->get($model->getKey());
            $value = $translation === null ? null : $this->pick($translation, $includeDrafts);

            if ($value !== null) {
                $values[$key] = $value;
                $written[] = [$translation, $value];
            }
        }

        $exists = is_file($path);

        if ($values === [] && ! $exists) {
            return;
        }

        $content = $file->format === FileFormat::Json
            ? $this->json->render($values)
            : $this->php->render(Arr::undot($values), $this->header($file, $source));

        if ($exists && $this->files->get($path) === $content) {
            $report->unchanged[] = $path;
        } else {
            if (! $report->dryRun) {
                $this->write($path, $content);
            }

            $report->written[] = $path;
        }

        $report->keys[$path] = count($values);

        if (! $report->dryRun) {
            foreach ($written as [$translation, $value]) {
                $hash = Fingerprint::of($value);

                if ($translation->exported_hash !== $hash) {
                    $translation->update(['exported_hash' => $hash]);
                }
            }
        }
    }

    /**
     * Keys in the existing target file whose value Prosetta did not write:
     * anything without a translation row, or whose hash differs from the
     * translation's exported_hash. Obsolete keys count as known.
     *
     * @return list<string>
     */
    private function conflicts(LangRoot $root, TranslationFile $file, string $locale): array {
        try {
            $onDisk = $this->reader->read($root, $locale, $file->group, $file->format);
        } catch (LangFileException) {
            return ['(unreadable file)'];
        }

        if ($onDisk === []) {
            return [];
        }

        $keys = $file->keys()->get()->keyBy(fn (TranslationKey $key) => $key->key);
        $translationModel = Settings::model('translation');
        $translations = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $keys->modelKeys())->get()->keyBy('key_id');
        $conflicts = [];

        foreach ($onDisk as $key => $value) {
            $model = $keys->get((string) $key);
            $translation = $model === null ? null : $translations->get($model->getKey());

            if ($translation === null || $translation->exported_hash !== Fingerprint::of($value)) {
                $conflicts[] = (string) $key;
            }
        }

        return $conflicts;
    }

    private function pick(Translation $translation, bool $includeDrafts): ?string {
        if ($includeDrafts
            && $translation->value !== null
            && $translation->status !== TranslationStatus::Rejected
            && ! $translation->hasBlockingIssues()) {
            return $translation->value;
        }

        return $translation->approved_value;
    }

    private function header(TranslationFile $file, string $source): string {
        $prefix = $file->namespace === KeyRef::ROOT ? '' : $file->namespace.'::';

        return "Generated by Prosetta from {$prefix}{$source}/{$file->group}.php. Edit the source file or use Prosetta;\nhand edits here are imported for review on the next sync.";
    }

    private function write(string $path, string $content): void {
        $this->files->ensureDirectoryExists(dirname($path));
        $temporary = $path.'.prosetta-'.bin2hex(random_bytes(4));
        $this->files->put($temporary, $content);

        if (! @rename($temporary, $path)) {
            $this->files->delete($temporary);

            throw new ProsettaException("Could not write [$path].");
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}
