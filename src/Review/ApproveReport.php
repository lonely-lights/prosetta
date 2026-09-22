<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

final class ApproveReport {
    /** @var list<int> */
    public array $approved = [];

    /** @var array<int, string> translation id => reason */
    public array $skipped = [];
}
