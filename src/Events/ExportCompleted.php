<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Export\ExportReport;

final class ExportCompleted {
    public function __construct(public readonly ExportReport $report) {}
}
