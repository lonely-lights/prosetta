<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;

final class ExportCommand extends Command {
    protected $signature = 'prosetta:export
        {--locale=* : Only these locales}
        {--namespace=* : Only these namespaces}
        {--include-drafts : Also write unreviewed drafts that have no blocking issues}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Write target-locale lang files from approved (or draft) translations.';

    public function handle(ProsettaManager $prosetta): int {
        $dryRun = (bool) $this->option('dry-run');
        $report = $prosetta->export($this->option('locale'), $this->option('namespace'), $this->option('include-drafts') ? true : null, $dryRun);

        foreach ($report->written as $path) {
            $this->line(($dryRun ? 'would write ' : 'wrote ').$path.' ('.($report->keys[$path] ?? 0).' keys)');
        }

        foreach ($report->refused as $path) {
            $this->components->warn("Refused (excluded path): $path");
        }

        $this->components->info(count($report->written).' written, '.count($report->unchanged).' unchanged, '.count($report->refused).' refused.');

        return self::SUCCESS;
    }
}
