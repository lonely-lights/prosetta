<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Automation\CycleReport;
use Throwable;

/** Dispatched by a queued cycle's batch once every job has run: approves, exports and reports. */
final class FinishCycle implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public string $batchId,
        public string $runId,
        public int $startedAt,
        public int $confirmed,
    ) {
        $connection = config('prosetta.queue.connection');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        $this->onQueue((string) config('prosetta.queue.name', 'translations'));
    }

    /** @throws Throwable when a database transaction fails */
    public function handle(Cycle $cycle): CycleReport {
        return $cycle->finish($this->runId, $this->startedAt, $this->confirmed, batchId: $this->batchId);
    }
}
