<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

use LonelyLights\Prosetta\Exceptions\ProsettaException;

/** Thrown before a call when a budget is already used up; $period is per_run, daily or monthly. */
final class BudgetExhausted extends ProsettaException {
    public function __construct(public readonly string $period) {
        parent::__construct("The $period token budget is used up.");
    }
}
