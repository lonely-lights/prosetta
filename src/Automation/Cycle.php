<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
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
    ) {}

    /** @throws Throwable when syncing, confirming or dispatching fails */
    public function run(bool $sync = false): CycleReport {
        $previous = State::get('cycle.batch');

        if (is_array($previous) && isset($previous['batch_id'])) {
            $batch = Bus::findBatch((string) $previous['batch_id']);

            if ($batch !== null && ! $batch->finished()) {
                return CycleReport::skip('previous cycle still running');
            }
        }

        $this->syncer->sync();
        $confirmed = $this->confirmer->confirmAll();
        $work = $this->work->build();
        $startedAt = now()->getTimestamp();
        $runId = (string) Str::uuid();

        if ($work === []) {
            return $this->finish($runId, $startedAt, $confirmed);
        }

        # A Suspension Keeps Only These Locales, so prosetta:resume Stays Within Them
        $scope = new RunScope(array_keys($work), [], [], false, $startedAt);

        if ($sync) {
            /** @var TranslateReport $report */
            $report = $this->translator->run($work, $scope, queue: false, runId: $runId);

            return $this->finish($runId, $startedAt, $confirmed, $report);
        }

        # The Batch Id Isn't Known Until Dispatch, so a Queued Cycle Uses It as Its Run Id (Its Jobs Record Usage Under It)
        $batch = $this->translator->run($work, $scope, queue: true, finally: static function (Batch $batch) use ($startedAt, $confirmed): void {
            FinishCycle::dispatch($batch->id, $batch->id, $startedAt, $confirmed);
        });

        if ($batch instanceof TranslateReport) {
            return $this->finish($runId, $startedAt, $confirmed, $batch);
        }

        State::put('cycle.batch', ['batch_id' => $batch->id, 'started_at' => $startedAt]);

        return new CycleReport(confirmed: $confirmed, batchId: $batch->id);
    }

    /**
     * Approves this cycle's clean drafts per automation.approve, exports the
     * languages that got approvals, records the heartbeat and reports.
     *
     * @param TranslateReport|null $inline a synchronous run's report; queued cycles count from the database
     * @throws Throwable when a database transaction fails
     */
    public function finish(string $runId, int $startedAt, int $confirmed, ?TranslateReport $inline = null, ?string $batchId = null): CycleReport {
        $drafts = $this->drafts($startedAt);
        $touched = $drafts->pluck('locale')->unique()->values()->all();
        $approvedPerLocale = [];

        foreach ($this->approvable($touched) as $locale) {
            $approved = count($this->review->approveClean($locale, strict: true)->approved);

            if ($approved > 0) {
                $approvedPerLocale[$locale] = $approved;
            }
        }

        $files = config('prosetta.automation.export', true) && $approvedPerLocale !== []
            ? $this->exporter->export(array_keys($approvedPerLocale))->written
            : [];

        $report = new CycleReport(
            drafted: $inline !== null ? count($inline->drafted) : $drafts->count(),
            updated: $inline !== null ? count($inline->updated) : $drafts->whereNotNull('approved_value')->count(),
            confirmed: $confirmed,
            approved: array_sum($approvedPerLocale),
            flagged: $drafts->filter(fn (Translation $translation) => ! empty($translation->issues))
                ->map(fn (Translation $translation) => $translation->locale.' '.$translation->key->ref()->toString())
                ->values()->all(),
            files: array_values($files),
            tokens: $this->usage->sum($runId),
            batchId: $batchId,
        );

        State::put('cycle.last_run', now()->getTimestamp());
        State::forget('cycle.batch');
        $this->events->dispatch(new CycleCompleted($report));

        return $report;
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
