<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Exceptions\ReviewConflict;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\ProsettaManager;
use LonelyLights\Prosetta\Translation\Estimator;
use Throwable;

/**
 * The review screens' bigger actions: approving or rejecting many items at
 * once, asking the AI again, and queuing a cycle. Every one checks the
 * viewer's permissions and the editable guard.
 */
final readonly class ReviewDesk {
    private const int CHUNK = 200;

    public function __construct(
        private ReviewService $service,
        private ReviewQueue $queue,
        private Estimator $estimator,
        private ProsettaManager $prosetta,
        private Cycle $cycle,
        private Authorizer $authorizer,
    ) {}

    /**
     * Approves every item in the queue matching the filter that the viewer
     * may review: never one with errors, and one with warnings only when asked.
     *
     * @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters
     * @throws Throwable when a database transaction fails
     */
    public function approveMatching(Viewer $viewer, array $filters, bool $includeWarnings = false): BatchReport {
        $this->unlocked($viewer);
        $report = new BatchReport;
        $expected = [];

        foreach ($this->queue->all($viewer, $filters) as $item) {
            if ($item->translationId === null || $item->candidate === null || ! in_array($item->reason, ['draft', 'flagged', 'pending'], true) || ! $viewer->canReview($item->locale)) {
                continue;
            }

            if ($item->blocking) {
                $report->skippedErrors++;

                continue;
            }

            if ($item->warnings && ! $includeWarnings) {
                $report->skippedWarnings++;

                continue;
            }

            $expected[$item->translationId] = (string) $item->fingerprint;
        }

        return $this->approveChunks($viewer, $expected, $report);
    }

    /**
     * @param array<int, string> $expected translation id => the fingerprint the page saw
     * @throws Throwable when a database transaction fails
     */
    public function approveMany(Viewer $viewer, array $expected): BatchReport {
        $this->unlocked($viewer);

        return $this->approveChunks($viewer, $expected, new BatchReport);
    }

    /**
     * @param array<int, string> $expected translation id => the fingerprint the page saw
     * @throws Throwable when a database transaction fails
     */
    public function rejectMany(Viewer $viewer, array $expected, string $note): BatchReport {
        $this->unlocked($viewer);
        $report = new BatchReport;

        foreach ($expected as $id => $fingerprint) {
            try {
                $this->service->reject((int) $id, $viewer->user, $note, expected: $fingerprint);
                $report->rejected++;
            } catch (ReviewConflict) {
                $report->conflicts++;
                $report->skipped[(int) $id] = 'conflict';
            }
        }

        return $report;
    }

    /**
     * @param array<string, list<string>> $refsByLocale locale => key refs
     * @return array<string, array{strings: int, chars: int, input: int, output: int, from_history: bool}>
     */
    public function estimateRedraft(array $refsByLocale): array {
        $estimates = [];

        foreach ($refsByLocale as $locale => $refs) {
            $estimates += $this->estimator->estimate([$locale], [], $refs, force: true);
        }

        return $estimates;
    }

    /**
     * Queues the chosen keys for a fresh AI draft; budgets and the circuit breaker apply as usual.
     *
     * @param array<string, list<string>> $refsByLocale locale => key refs
     * @throws Throwable when a batch cannot be dispatched
     */
    public function redraft(Viewer $viewer, array $refsByLocale): void {
        $this->unlocked($viewer);

        foreach ($refsByLocale as $locale => $refs) {
            $this->prosetta->translate([$locale], [], $refs, force: true, queue: true, by: $viewer->user);
        }
    }

    /** @throws Throwable when the cycle's batch cannot be dispatched */
    public function runCycle(Viewer $viewer): void {
        $this->unlocked($viewer);
        $this->authorizer->authorize($viewer->user, Ability::Manage);
        $this->cycle->run();
    }

    /**
     * @param array<int, string> $expected
     * @throws Throwable when a database transaction fails
     */
    private function approveChunks(Viewer $viewer, array $expected, BatchReport $report): BatchReport {
        foreach (array_chunk($expected, self::CHUNK, preserve_keys: true) as $chunk) {
            $result = $this->service->approve(array_keys($chunk), $viewer->user, expected: $chunk);
            $report->approved += count($result->approved);

            foreach ($result->skipped as $id => $reason) {
                $report->skipped[(int) $id] = $reason;

                if ($reason === 'conflict') {
                    $report->conflicts++;
                }
            }
        }

        return $report;
    }

    private function unlocked(Viewer $viewer): void {
        if ($viewer->user !== null && ! Viewer::editable()) {
            throw ReviewLocked::make();
        }
    }
}
