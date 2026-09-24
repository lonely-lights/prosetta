<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Automation\Health;

final class HealthCommand extends Command {
    protected $signature = 'prosetta:health';

    protected $description = 'Check the health of the Prosetta automation system.';

    /**
     * Each problem line starts with a stable code in brackets ([cycle_stale],
     * [circuit_halted:{name}], [budget:{period}]) and then the human text, so
     * a host can remove duplicate alerts by the codes while the text changes.
     */
    public function handle(Health $health): int {
        $problems = $health->problems();

        if ($problems === []) {
            $this->line('healthy');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->line($problem);
        }

        return self::FAILURE;
    }
}
