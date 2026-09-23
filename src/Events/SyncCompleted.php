<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Sync\SyncReport;

final readonly class SyncCompleted {
    public function __construct(public SyncReport $report) {}
}
