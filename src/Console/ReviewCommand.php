<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LonelyLights\Prosetta\Console\Concerns\ReadsInput;
use LonelyLights\Prosetta\ProsettaManager;
use LonelyLights\Prosetta\Review\ReviewItem;
use Throwable;

final class ReviewCommand extends Command {
    use ReadsInput;

    protected $signature = 'prosetta:review
        {locale : The locale to review}
        {--approve-clean : Approve every current candidate without blocking issues}
        {--namespace= : Only this namespace}
        {--limit=20 : Rows to list}';

    protected $description = 'List what waits for review in a locale, or approve every clean candidate.';

    /** @throws Throwable when a database transaction fails */
    public function handle(ProsettaManager $prosetta): int {
        $locale = $this->text('locale');
        $namespace = $this->option('namespace');

        if ($this->option('approve-clean')) {
            $report = $prosetta->approveClean($locale, is_string($namespace) ? $namespace : null);
            $this->components->info(count($report->approved).' approved, '.count($report->skipped).' skipped.');

            foreach ($report->skipped as $id => $reason) {
                $this->components->warn("Translation $id skipped: $reason");
            }

            return self::SUCCESS;
        }

        $page = $prosetta->reviewQueue($locale, array_filter(['namespace' => $namespace]), (int) $this->option('limit'));

        $this->table(['Key', 'Status', 'Stale', 'Issues', 'Candidate'], array_map(fn (ReviewItem $item) => [
            $item->keyRef, $item->status, $item->stale ? 'yes' : '', count($item->issues), Str::limit((string) $item->candidate, 60),
        ], $page->items()));
        $this->components->info("{$page->total()} waiting in $locale.");

        return self::SUCCESS;
    }
}
