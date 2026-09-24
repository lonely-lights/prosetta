<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Automation\CycleReport;

final readonly class CycleCompleted {
    public function __construct(public CycleReport $report) {}
}
