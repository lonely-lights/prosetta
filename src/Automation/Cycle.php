<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use Carbon\CarbonInterface;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\CycleCompleted;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Jobs\FinishCycle;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Translation\TranslateReport;
use LonelyLights\Prosetta\Translation\Translator;
use Throwable;

/**
 * One background pass: sync, confirm cosmetic edits, update edited keys and
 * draft missing ones as a single run, then approve clean drafts, export and
 * report. Queued, the second half runs in FinishCycle once the batch is done.
 */
final readonly class Cycle {
    public function __construct(
        private Syncer $syncer,
        private CosmeticConfirmer $confirmer,
        private CycleWork $work,
        private Translator $translator,
        private ReviewService $review,
        private Exporter $exporter,
        private UsageLedger $usage,
        private LocaleSource $locales,
        private Dispatcher $events,
        private CycleFailures $failures,
    ) {}

    /** @throws Throwable when syncing, confirming or dispatching fails */
    public function run(bool $sync = false): CycleReport {
        $previous = State::get('cycle.batch');

        # Only FinishCycle Ends a Queued Cycle: a Batch Is "Finished" Before Its FinishCycle Has Run, and at Once When Cancelled
        if (is_array($previous) && isset($previous['batch_id'])) {
            $batch = Bus::findBatch((string) $previous['batch_id']);

            if (! $this->isAbandoned($batch, $previous, now())) {
                return CycleReport::skip('previous cycle still running');
            }

            Log::channel(config('prosetta.log_channel'))->warning("[prosetta] Cycle batch {$previous['batch_id']} was abandoned without finishing; starting a new cycle.");
            State::forget('cycle.batch');
        }

        $this->syncer->sync();
        $confirmed = $this->confirmer->confirmAll();
        ['work' => $work, 'held' => $held] = $this->work->plan();
        $startedAt = now()->getTimestamp();
        $runId = (string) Str::uuid();

        if ($work === []) {
            return $this->finish($runId, $startedAt, $confirmed, held: $held);
        }

        # Marked as a Cycle, so prosetta:resume Clears a Suspension of It Instead of Re-Translating a Wider Scope
        $scope = new RunScope(array_keys($work), [], [], false, $startedAt, cycle: true);

        if ($sync) {
            /** @var TranslateReport $report */
            $report = $this->translator->run($work, $scope, queue: false, runId: $runId);

            return $this->finish($runId, $startedAt, $confirmed, $report, held: $held);
        }

        # The Batch Id Isn't Known Until Dispatch, so a Queued Cycle Uses It as Its Run Id (Its Jobs Record Usage Under It)
        $batch = $this->translator->run($work, $scope, finally: static function (Batch $batch) use ($startedAt, $confirmed, $held): void {
            FinishCycle::dispatch($batch->id, $batch->id, $startedAt, $confirmed, $held);
        });

        if ($batch instanceof TranslateReport) {
            return $this->finish($runId, $startedAt, $confirmed, $batch, held: $held);
        }

        # On a Sync Queue (or a Fast Worker) the Batch and Its FinishCycle Can Complete Inside dispatch(): Then There's Nothing to Guard
        if (State::get('cycle.finished_batch') !== $batch->id) {
            State::put('cycle.batch', ['batch_id' => $batch->id, 'started_at' => $startedAt]);
        }

        return new CycleReport(confirmed: $confirmed, batchId: $batch->id);
    }

    /**
     * Approves this cycle's clean drafts per automation.approve, exports the
     * languages that got approvals, records the heartbeat and reports. The
     * export leaves out any lang file a person's candidate is waiting in, so
     * the file keeps their hand edit until it is reviewed.
     *
     * @param TranslateReport|null $inline a synchronous run's report; queued cycles count from the database
     * @param list<string> $held keys CycleWork held back from this cycle's work, with their reasons
     * @throws Throwable when a database transaction fails
     */
    public function finish(string $runId, int $startedAt, int $confirmed, ?TranslateReport $inline = null, ?string $batchId = null, array $held = []): CycleReport {
        $drafts = $this->drafts($startedAt);
        $touched = $drafts->pluck('locale')->unique()->values()->all();
        $approvedPerLocale = [];

        foreach ($this->approvable($touched) as $locale) {
            $approved = count($this->review->approveClean($locale, strict: true, since: $startedAt)->approved);

            if ($approved > 0) {
                $approvedPerLocale[$locale] = $approved;
            }
        }

        $files = [];
        $heldFiles = [];

        if (config('prosetta.automation.export', true) && $approvedPerLocale !== []) {
            $exported = $this->exporter->export(array_keys($approvedPerLocale), hold: $this->filesAwaitingReview(array_keys($approvedPerLocale)));
            $files = $exported->written;
            $heldFiles = array_map(
                fn (array $file) => $file['locale'].' '.$this->relative($file['path']).' (export held: a person\'s edit in this file awaits review)',
                $exported->held,
            );
        }

        $report = new CycleReport(
            drafted: $inline !== null ? count($inline->drafted) : $drafts->count(),
            updated: $inline !== null ? count($inline->updated) : $drafts->whereNotNull('approved_value')->count(),
            confirmed: $confirmed,
            approved: array_sum($approvedPerLocale),
            flagged: [
                ...$drafts->filter(fn (Translation $translation) => ! empty($translation->issues))
                    ->map(fn (Translation $translation) => $translation->locale.' '.$translation->key->ref()->toString())
                    ->values()->all(),
                ...$this->failures->failedIn($runId),
                ...$held,
                ...$heldFiles,
            ],
            files: array_values($files),
            tokens: $this->usage->sum($runId),
            batchId: $batchId,
        );

        State::put('cycle.last_run', now()->getTimestamp());

        if ($batchId !== null) {
            State::put('cycle.finished_batch', $batchId);
        }

        $stored = State::get('cycle.batch');

        # Release Only Our Own Guard: a Late FinishCycle Must Never Unblock a Newer Cycle
        if ($batchId !== null && is_array($stored) && ($stored['batch_id'] ?? null) === $batchId) {
            State::forget('cycle.batch');
        }
        $this->events->dispatch(new CycleCompleted($report));

        return $report;
    }

    /**
     * Whether a stored cycle batch can no longer finish on its own: it's gone
     * (pruned), or it settled (every job ran or failed, or it was cancelled)
     * longer ago than the grace period, max(60 minutes, 2 x automation.every),
     * and its FinishCycle still hasn't released the guard.
     *
     * @param array{batch_id?: string, started_at?: int} $stored
     */
    public function isAbandoned(?Batch $batch, array $stored, CarbonInterface $now): bool {
        if ($batch === null) {
            return true;
        }

        if ($batch->pendingJobs !== $batch->failedJobs && ! $batch->cancelled()) {
            return false;
        }

        $settledAt = $batch->finishedAt ?? $batch->cancelledAt
            ?? (isset($stored['started_at']) ? Carbon::createFromTimestamp((int) $stored['started_at'], $now->getTimezone()) : null);

        if ($settledAt === null) {
            return false;
        }

        $grace = max(60, 2 * (int) config('prosetta.automation.every', 0));

        return $now->greaterThanOrEqualTo($settledAt->copy()->addMinutes($grace));
    }

    /**
     * AI drafts written since the cycle started, read before anything is approved.
     *
     * @return Collection<int, Translation>
     */
    private function drafts(int $startedAt): Collection {
        $model = Settings::model('translation');
        $targets = array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets());

        return $model::query()->with('key.file')
            ->whereIn('locale', $targets)
            ->where('origin', TranslationOrigin::Ai->value)
            ->where('status', TranslationStatus::Draft->value)
            ->where('updated_at', '>=', now()->setTimestamp($startedAt))
            ->orderBy('id')
            ->get();
    }

    /**
     * The lang files holding a current candidate from a person or an import:
     * not AI, still Draft or NeedsReview, and made from the key's current English.
     *
     * @param list<string> $locales
     * @return array<string, list<int>> locale => file ids
     */
    private function filesAwaitingReview(array $locales): array {
        $translationTable = Settings::table('translations');
        $keyTable = Settings::table('keys');
        $model = Settings::model('translation');
        $held = [];

        $rows = $model::query()->toBase()
            ->join($keyTable, "$keyTable.id", '=', "$translationTable.key_id")
            ->whereIn("$translationTable.locale", $locales)
            ->where("$translationTable.origin", '!=', TranslationOrigin::Ai->value)
            ->whereIn("$translationTable.status", [TranslationStatus::Draft->value, TranslationStatus::NeedsReview->value])
            ->whereNotNull("$translationTable.value")
            ->whereColumn("$translationTable.source_hash", "$keyTable.source_hash")
            ->whereNull("$keyTable.obsolete_at")
            ->distinct()
            ->get(["$translationTable.locale", "$keyTable.file_id"]);

        foreach ($rows as $row) {
            $held[(string) $row->locale][] = (int) $row->file_id;
        }

        return $held;
    }

    /** The path relative to the app when it is inside it, as a person would look for the file. */
    private function relative(string $path): string {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * @param list<string> $touched locales this cycle drafted in
     * @return list<string>
     */
    private function approvable(array $touched): array {
        $approve = config('prosetta.automation.approve', 'all');

        return match (true) {
            $approve === 'all' => $touched,
            is_array($approve) => array_values(array_intersect($touched, array_map('strval', $approve))),
            default => [],
        };
    }
}
