<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;

final class StatsCommand extends Command {
    protected $signature = 'prosetta:stats {--locale= : Only this locale}';

    protected $description = 'Show translation progress per locale and namespace.';

    public function handle(ProsettaManager $prosetta): int {
        $locale = $this->option('locale');
        $rows = [];

        foreach ($prosetta->stats(is_string($locale) ? $locale : null) as $code => $namespaces) {
            foreach ($namespaces as $namespace => $row) {
                $rows[] = [$code, $namespace, ...array_values($row)];
            }
        }

        $this->table(['Locale', 'Namespace', 'Keys', 'Approved', 'Drafts', 'Needs review', 'Stale', 'Missing', 'Issues', 'Tokens'], $rows);

        return self::SUCCESS;
    }
}
