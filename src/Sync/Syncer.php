<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Sync;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Discovery\LangReader;
use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Discovery\RootDiscovery;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\KeyAdded;
use LonelyLights\Prosetta\Events\KeyChanged;
use LonelyLights\Prosetta\Events\KeyObsoleted;
use LonelyLights\Prosetta\Events\SyncCompleted;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\Placeholders;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Reads the source locale's files into keys, then imports what the target
 * locales' files already say. Never writes a file. One transaction: a
 * broken source file aborts everything, so nothing is wrongly obsoleted.
 */
final class Syncer {
    public function __construct(
        private readonly RootDiscovery $discovery,
        private readonly LangReader $reader,
        private readonly LocaleSource $locales,
        private readonly PlaceholderGuard $guard,
        private readonly Dispatcher $events,
    ) {}

    /** @param list<string>|null $namespaces */
    public function sync(?array $namespaces = null, bool $quiet = false): SyncReport {
        $report = new SyncReport;
        $pending = [];

        DB::transaction(function () use ($namespaces, $report, &$pending): void {
            $source = $this->locales->source();
            $targets = array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets());

            foreach ($this->discovery->roots($namespaces) as $root) {
                $this->syncRoot($root, $source, $targets, $report, $pending);
            }
        });

        if (! $quiet) {
            foreach ($pending as $event) {
                $this->events->dispatch($event);
            }

            $this->events->dispatch(new SyncCompleted($report));
        }

        return $report;
    }

    /**
     * @param list<string> $targets
     * @param list<object> $pending
     */
    private function syncRoot(LangRoot $root, string $source, array $targets, SyncReport $report, array &$pending): void {
        $fileModel = Settings::model('file');
        $seen = [];

        foreach ($this->reader->groups($root, $source) as ['group' => $group, 'format' => $format]) {
            $values = $this->reader->read($root, $source, $group, $format);
            /** @var TranslationFile $file */
            $file = $fileModel::query()->firstOrCreate(['namespace' => $root->namespace, 'group' => $group], ['format' => $format]);
            $seen[] = $file->getKey();
            $keys = $this->syncKeys($file, $values, $report, $pending);

            foreach ($targets as $locale) {
                $this->importTarget($root, $file, $keys, $locale, $report);
            }
        }

        # A Group Whose Source File Disappeared: Every Key in It Becomes Obsolete
        $fileModel::query()->where('namespace', $root->namespace)->whereKeyNot($seen)->get()
            ->each(function (TranslationFile $file) use ($report, &$pending): void {
                $this->syncKeys($file, [], $report, $pending);
            });
    }

    /**
     * @param array<string, string> $values
     * @param list<object> $pending
     * @return Collection<string, TranslationKey>
     */
    private function syncKeys(TranslationFile $file, array $values, SyncReport $report, array &$pending): Collection {
        $keyModel = Settings::model('key');
        $existing = $keyModel::query()->where('file_id', $file->getKey())->get()->keyBy('key');
        $current = new Collection;

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $hash = Fingerprint::of($value);
            /** @var TranslationKey|null $model */
            $model = $existing->get($key);

            if ($model === null) {
                $model = $keyModel::query()->create([
                    'file_id' => $file->getKey(), 'key' => $key, 'source_value' => $value,
                    'source_hash' => $hash, 'placeholders' => Placeholders::unique($value),
                ]);
                $model->setRelation('file', $file);
                $report->added[] = $model->ref()->toString();
                $pending[] = new KeyAdded($model);
            } elseif ($model->source_hash !== $hash) {
                $previous = $model->source_value;
                $model->update(['source_value' => $value, 'source_hash' => $hash, 'placeholders' => Placeholders::unique($value), 'obsolete_at' => null]);
                $model->setRelation('file', $file);
                $report->changed[] = $model->ref()->toString();
                $pending[] = new KeyChanged($model, $previous);
            } elseif ($model->obsolete_at !== null) {
                $model->update(['obsolete_at' => null]);
                $model->setRelation('file', $file);
                $report->restored[] = $model->ref()->toString();
            }

            $current->put($key, $model);
        }

        foreach ($existing as $key => $model) {
            if (! array_key_exists($key, $values) && $model->obsolete_at === null) {
                $model->update(['obsolete_at' => now()]);
                $model->setRelation('file', $file);
                $report->obsoleted[] = $model->ref()->toString();
                $pending[] = new KeyObsoleted($model);
            }
        }

        return $current;
    }

    /** @param Collection<string, TranslationKey> $keys */
    private function importTarget(LangRoot $root, TranslationFile $file, Collection $keys, string $locale, SyncReport $report): void {
        $values = $this->reader->read($root, $locale, $file->group, $file->format);

        if ($values === [] || $keys->isEmpty()) {
            return;
        }

        $translationModel = Settings::model('translation');
        $existing = $translationModel::query()->where('locale', $locale)
            ->whereIn('key_id', $keys->map(fn (TranslationKey $key) => $key->getKey())->values()->all())
            ->get()->keyBy('key_id');

        foreach ($keys as $key => $model) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];
            $fileHash = Fingerprint::of($value);
            $issues = Issue::store($this->guard->check($model->source_value, $value, $locale));
            $translation = $existing->get($model->getKey());

            if ($translation === null) {
                $clean = ! Issue::anyBlocking($issues);
                $translationModel::query()->create([
                    'key_id' => $model->getKey(), 'locale' => $locale,
                    'value' => $value, 'source_hash' => $model->source_hash,
                    'approved_value' => $clean ? $value : null,
                    'approved_source_hash' => $clean ? $model->source_hash : null,
                    'status' => $clean ? TranslationStatus::Approved : TranslationStatus::NeedsReview,
                    'origin' => TranslationOrigin::Imported,
                    'issues' => $issues, 'exported_hash' => $fileHash,
                ]);
                $report->imported++;

                continue;
            }

            if ($translation->exported_hash === $fileHash) {
                continue;
            }

            if ($value === $translation->value || $value === $translation->approved_value) {
                $translation->update(['exported_hash' => $fileHash]);

                continue;
            }

            # Someone Edited Prosetta's Output by Hand: Keep It as a Candidate, Never as Approved
            $previous = $translation->value;
            $translation->update([
                'value' => $value, 'source_hash' => $model->source_hash,
                'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Manual,
                'issues' => $issues, 'exported_hash' => $fileHash,
            ]);
            $translation->reviews()->create([
                'reviewer_id' => null, 'action' => ReviewAction::Imported,
                'previous_value' => $previous, 'new_value' => $value, 'notes' => 'Edited by hand in the lang file.',
            ]);
            $report->handEdits[] = $locale.' '.$model->ref()->toString();
        }
    }
}
