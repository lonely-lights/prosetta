<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;

final class Translator {
    public function __construct(
        private readonly LocaleSource $locales,
        private readonly KeyFinder $finder,
        private readonly TranslationRunner $runner,
    ) {}

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys key references
     * @return array<string, array<int, list<int>>> locale => file id => key ids
     */
    public function workList(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false): array {
        $targets = array_values(array_filter(
            array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets()),
            fn (string $code) => $locales === [] || in_array($code, $locales, true),
        ));
        $keyTable = Settings::table('keys');
        $fileTable = Settings::table('files');
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');

        $query = $keyModel::query()->select("$keyTable.*")
            ->join($fileTable, "$fileTable.id", '=', "$keyTable.file_id")
            ->whereNull("$keyTable.obsolete_at")
            ->orderBy("$keyTable.id");

        if ($namespaces !== []) {
            $query->whereIn("$fileTable.namespace", $namespaces);
        }

        if ($keys !== []) {
            $query->whereKey(array_map(
                fn (string $ref) => $this->finder->find($ref)?->getKey() ?? throw new ProsettaException("No key [$ref]."),
                $keys,
            ));
        }

        $candidates = $query->get();
        $work = [];

        foreach ($targets as $locale) {
            $existing = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $candidates->modelKeys())->get()->keyBy('key_id');

            foreach ($candidates as $key) {
                /** @var TranslationKey $key */
                if ($force || WorkState::needsWork($key, $existing->get($key->getKey()))) {
                    $work[$locale][(int) $key->file_id][] = (int) $key->getKey();
                }
            }
        }

        return $work;
    }

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys
     */
    public function translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true): Batch|TranslateReport {
        $work = $this->workList($locales, $namespaces, $keys, $force);
        $size = max(1, (int) config('prosetta.ai.batch', 25));

        if (! $queue) {
            $report = new TranslateReport;

            foreach ($work as $locale => $files) {
                foreach ($files as $ids) {
                    foreach (array_chunk($ids, $size) as $chunk) {
                        $report->merge($this->runner->run($locale, $chunk, $force));
                    }
                }
            }

            return $report;
        }

        $jobs = [];

        foreach ($work as $locale => $files) {
            foreach ($files as $fileId => $ids) {
                foreach (array_chunk($ids, $size) as $chunk) {
                    $jobs[] = new TranslateBatch($locale, (int) $fileId, $chunk, $force);
                }
            }
        }

        if ($jobs === []) {
            return new TranslateReport;
        }

        $pending = Bus::batch($jobs)->name('prosetta:translate')->allowFailures();
        $connection = config('prosetta.queue.connection');

        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        return $pending->onQueue((string) config('prosetta.queue.name', 'translations'))->dispatch();
    }
}
