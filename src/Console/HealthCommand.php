<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Support\State;

final class HealthCommand extends Command {
    protected $signature = 'prosetta:health';

    protected $description = 'Check the health of the Prosetta automation system.';

    public function handle(Circuits $circuits, Budget $budget): int {
        $problems = [];

        // Check if automation is on and last cycle is missing or old
        $every = config('prosetta.automation.every');
        if ($every !== null && (int) $every > 0) {
            $lastRun = State::get('cycle.last_run');
            if ($lastRun === null) {
                $problems[] = 'cycle: automation is on but no cycle has run yet';
            } else {
                $maxAge = (int) $every * 3 * 60; // 3 * every minutes in seconds
                $age = now()->getTimestamp() - (int) $lastRun;
                if ($age > $maxAge) {
                    $problems[] = 'cycle: last cycle was '.floor($age / 60).' minutes ago (max: '.(int)($every * 3).')';
                }
            }
        }

        // Check if any circuit is halted
        foreach ($circuits->names() as $name) {
            $state = $circuits->for($name)->state();
            if ($state['state'] === 'open' && $state['reason'] === 'halt') {
                $problems[] = "circuit [$name]: halted ({$state['halt']})";
            }
        }

        // Check if daily or monthly budget is exhausted
        $exhausted = $budget->exhausted(null);
        if ($exhausted !== null) {
            $problems[] = "$exhausted budget: exhausted";
        }

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
