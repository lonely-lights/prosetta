<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

/** What a batch action did, so a page can say "312 approved; 9 with warnings and 3 with errors left for you". */
final class BatchReport {
    public int $approved = 0;

    public int $rejected = 0;

    public int $skippedWarnings = 0;

    public int $skippedErrors = 0;

    public int $conflicts = 0;

    /** Ids skipped before any write: in a language the viewer can't review, or no longer there. */
    public int $forbidden = 0;

    /** @var array<int, string> translation id => reason */
    public array $skipped = [];

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
