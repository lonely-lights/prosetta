<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Export\ExportReport;

final readonly class ExportCompleted {
    public function __construct(public ExportReport $report) {}
}
