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

    /**
     * Each problem line starts with a stable code in brackets ([cycle_stale],
     * [circuit_halted:{name}], [budget:{period}]) and then the human text, so
     * a host can remove duplicate alerts by the codes while the text changes.
     */
    public function handle(Circuits $circuits, Budget $budget): int {
        $problems = [];

        # Automation Is On and the Last Cycle Is Missing or Old
        $every = config('prosetta.automation.every');

        if ($every !== null && (int) $every > 0) {
            $lastRun = State::get('cycle.last_run');
            $maxMinutes = (int) $every * 3;

            if ($lastRun === null) {
                $problems[] = '[cycle_stale] automation is on but no cycle has run yet';
            } elseif (($age = now()->getTimestamp() - (int) $lastRun) > $maxMinutes * 60) {
                $problems[] = '[cycle_stale] last cycle was '.floor($age / 60)." minutes ago (max: $maxMinutes)";
            }
        }

        # A Halted Circuit
        foreach ($circuits->names() as $name) {
            $state = $circuits->for($name)->state();

            if ($state['state'] === 'open' && $state['reason'] === 'halt') {
                $problems[] = "[circuit_halted:$name] circuit [$name]: halted ({$state['halt']})";
            }
        }

        # The Daily or Monthly Budget Is Spent
        $exhausted = $budget->exhausted(null);

        if ($exhausted !== null) {
            $problems[] = "[budget:$exhausted] $exhausted budget: exhausted";
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
