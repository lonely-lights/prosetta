<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Support\State;

/**
 * What's wrong with the automation right now, as the lines prosetta:health
 * prints: each starts with a stable code in brackets ([cycle_stale],
 * [circuit_halted:{name}], [budget:{period}]) and then the human text.
 */
final readonly class Health {
    public function __construct(private Circuits $circuits, private Budget $budget) {}

    /** @return list<string> */
    public function problems(): array {
        $problems = [];
        $every = config('prosetta.automation.every');

        # Automation Is On and the Last Cycle Is Missing or Old
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
        foreach ($this->circuits->names() as $name) {
            $state = $this->circuits->for($name)->state();

            if ($state['state'] === 'open' && $state['reason'] === 'halt') {
                $problems[] = "[circuit_halted:$name] circuit [$name]: halted ({$state['halt']})";
            }
        }

        # The Daily or Monthly Budget Is Spent
        if (($exhausted = $this->budget->exhausted(null)) !== null) {
            $problems[] = "[budget:$exhausted] $exhausted budget: exhausted";
        }

        return $problems;
    }
}
