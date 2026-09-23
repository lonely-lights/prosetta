<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Automation\CycleWork;
use LonelyLights\Prosetta\Resilience\Suspensions;
use Throwable;

final class CycleCommand extends Command {
    protected $signature = 'prosetta:cycle
        {--sync : Run inline and exit 1 when anything is flagged, stale or suspended (for CI)}';

    protected $description = 'Sync, confirm cosmetic edits, update edited and draft missing translations, then approve and export per config.';

    /** @throws Throwable when the cycle fails */
    public function handle(Cycle $cycle, CycleWork $work, Suspensions $suspensions): int {
        $sync = (bool) $this->option('sync');
        $report = $cycle->run($sync);

        if ($report->skipped) {
            $this->line("skipped: $report->reason");
        } elseif ($report->batchId !== null) {
            $this->components->info("Queued cycle batch $report->batchId".($report->confirmed > 0 ? " ($report->confirmed cosmetic edit(s) confirmed)." : '.'));
        } else {
            $this->components->info(sprintf(
                '%d drafted (%d updates), %d confirmed, %d approved, %d flagged; %d file(s) written; %d tokens.',
                $report->drafted, $report->updated, $report->confirmed, $report->approved, count($report->flagged), count($report->files), $report->tokens,
            ));
        }

        if (! $sync) {
            return self::SUCCESS;
        }

        foreach ($report->flagged as $ref) {
            $this->components->warn("Needs attention: $ref");
        }

        $stale = count($work->stale());
        $suspended = count($suspensions->all());

        if ($stale > 0) {
            $this->components->warn("$stale stale approved translation(s) still made from older English.");
        }

        if ($suspended > 0) {
            $this->components->warn("$suspended suspended run(s) waiting for prosetta:resume.");
        }

        return $report->flagged === [] && $stale === 0 && $suspended === 0 ? self::SUCCESS : self::FAILURE;
    }
}
