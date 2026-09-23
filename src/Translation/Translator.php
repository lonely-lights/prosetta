<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Exceptions\MissingDriverException;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Exceptions\Provider\ProviderException;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Resilience\BudgetExhausted;
use LonelyLights\Prosetta\Resilience\CallDeferred;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;
use Throwable;

final readonly class Translator {
    public function __construct(
        private LocaleSource $locales,
        private KeyFinder $finder,
        private TranslationRunner $runner,
        private Suspensions $suspensions,
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
     * @throws Throwable when the queued batch cannot be dispatched
     */
    public function translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true): Batch|TranslateReport {
        $work = $this->workList($locales, $namespaces, $keys, $force);
        $size = max(1, (int) config('prosetta.ai.batch', 25));
        $scope = new RunScope(array_values($locales), array_values($namespaces), array_values($keys), $force);

        if (! $queue) {
            $report = new TranslateReport;
            $runId = (string) Str::uuid();

            foreach ($work as $locale => $files) {
                foreach ($files as $ids) {
                    foreach (array_chunk($ids, $size) as $chunk) {
                        try {
                            $report->merge($this->runner->run($locale, $chunk, $force, $runId));
                        } catch (CallDeferred|ProviderException|BudgetExhausted $e) {
                            $report->stopped = $e->getMessage();
                            $this->suspendSync($e, $scope);

                            return $report;
                        }
                    }
                }
            }

            return $report;
        }

        $jobs = [];

        foreach ($work as $locale => $files) {
            foreach ($files as $fileId => $ids) {
                foreach (array_chunk($ids, $size) as $chunk) {
                    $jobs[] = new TranslateBatch($locale, $fileId, $chunk, $force, $scope->toArray());
                }
            }
        }

        if ($jobs === []) {
            return new TranslateReport;
        }

        # Fail Here, Not in Every Queued Job
        if (! app()->bound(TranslationDriver::class)) {
            throw MissingDriverException::make();
        }

        $pending = Bus::batch($jobs)->name('prosetta:translate')->allowFailures();
        $connection = config('prosetta.queue.connection');

        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        return $pending->onQueue((string) config('prosetta.queue.name', 'translations'))->dispatch();
    }

    /** A synchronous run suspends like a queued one, except when only its own per-run budget ran out. */
    private function suspendSync(CallDeferred|ProviderException|BudgetExhausted $e, RunScope $scope): void {
        [$circuit, $reason] = match (true) {
            $e instanceof BudgetExhausted => ['budget', $e->period],
            $e instanceof CallDeferred => [$e->circuit, $e->reason === 'held' ? 'halted' : 'outage'],
            default => [(string) $e->circuit, class_basename($e)],
        };

        if ($reason !== 'per_run') {
            $this->suspensions->suspend($circuit, $scope, $reason);
        }
    }
}
