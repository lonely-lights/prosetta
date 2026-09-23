<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;
use Throwable;

final class SyncCommand extends Command {
    protected $signature = 'prosetta:sync
        {--namespace=* : Only these namespaces (* is the root lang folder)}
        {--check : Change nothing; exit 1 when anything is missing, stale, unreviewed or broken}';

    protected $description = "Read the source-language files and bring Prosetta's keys and imported translations up to date.";

    /** @throws Throwable when a database transaction fails */
    public function handle(ProsettaManager $prosetta): int {
        $namespaces = $this->option('namespace');
        $check = (bool) $this->option('check');
        $report = $prosetta->sync($namespaces === [] ? null : $namespaces, $check);

        $this->table(
            ['Added', 'Changed', 'Restored', 'Obsolete', 'Imported', 'Hand edits'],
            [[count($report->added), count($report->changed), count($report->restored), count($report->obsoleted), $report->imported, count($report->handEdits)]],
        );

        foreach ($report->handEdits as $edit) {
            $this->components->warn("Hand edit imported for review: $edit");
        }

        if (! $check) {
            return self::SUCCESS;
        }

        if ($report->outstanding === 0) {
            $this->components->info('Nothing outstanding.');

            return self::SUCCESS;
        }

        $this->components->error("{$report->outstanding} translation(s) missing, stale, awaiting review or broken. Run prosetta:stats for detail.");

        return self::FAILURE;
    }
}
