<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Export\ExportReport;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Queries\Stats;
use LonelyLights\Prosetta\Review\ApproveReport;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Renamer;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Sync\SyncReport;
use LonelyLights\Prosetta\Translation\TranslateReport;
use LonelyLights\Prosetta\Translation\Translator;

/** The public surface: what host controllers, commands and the facade call. */
class ProsettaManager {
    public function __construct(
        private readonly Syncer $syncer,
        private readonly Renamer $renamer,
        private readonly Translator $translator,
        private readonly ReviewService $reviews,
        private readonly ReviewQueue $queue,
        private readonly Exporter $exporter,
        private readonly Stats $stats,
        private readonly KeyFinder $finder,
        private readonly Authorizer $authorizer,
        private readonly LocaleSource $locales,
    ) {}

    /**
     * With $check, runs the whole sync inside a transaction that is rolled
     * back: nothing is kept, and $report->outstanding says how much work waits.
     *
     * @param list<string>|null $namespaces
     */
    public function sync(?array $namespaces = null, bool $check = false, ?Authenticatable $by = null): SyncReport {
        $this->authorizer->authorize($by, Ability::Manage);

        if (! $check) {
            return $this->syncer->sync($namespaces);
        }

        DB::beginTransaction();

        try {
            $report = $this->syncer->sync($namespaces, quiet: true);
            $report->outstanding = $this->stats->outstanding();
        } finally {
            DB::rollBack();
        }

        return $report;
    }

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys
     */
    public function translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true, ?Authenticatable $by = null): Batch|TranslateReport {
        $codes = $locales !== [] ? $locales : array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets());

        foreach ($codes as $code) {
            $this->authorizer->authorize($by, Ability::Translate, $code);
        }

        return $this->translator->translate($locales, $namespaces, $keys, $force, $queue);
    }

    /** @param array<string, mixed> $filters */
    public function reviewQueue(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator {
        return $this->queue->forLocale($locale, $filters, $perPage);
    }

    public function edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation {
        return $this->reviews->edit($translationId, $value, $by, $notes, $approve);
    }

    /** @param int|list<int> $translationIds */
    public function approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null): ApproveReport {
        return $this->reviews->approve($translationIds, $by, $notes);
    }

    public function approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null): ApproveReport {
        return $this->reviews->approveClean($locale, $namespace, $group, $by);
    }

    public function reject(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation {
        return $this->reviews->reject($translationId, $by, $notes);
    }

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     */
    public function export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, ?Authenticatable $by = null): ExportReport {
        $this->authorizer->authorize($by, Ability::Manage);

        return $this->exporter->export($locales, $namespaces, $includeDrafts, $dryRun);
    }

    public function rename(string $from, string $to, ?Authenticatable $by = null): TranslationKey {
        $this->authorizer->authorize($by, Ability::Manage);

        return $this->renamer->rename($from, $to);
    }

    /** @return array<string, array<string, array<string, int>>> */
    public function stats(?string $locale = null): array {
        return $this->stats->summary($locale);
    }

    public function lookup(string $keyRef): ?TranslationKey {
        return $this->finder->find($keyRef);
    }

    public function authorizeUsing(Closure $callback): void {
        $this->authorizer->using($callback);
    }
}
