<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

/** A token budget (per_run, daily or monthly) was used up. Raised once per run, day or month. */
final readonly class BudgetReached {
    public function __construct(public string $period, public int $used, public int $limit) {}
}
