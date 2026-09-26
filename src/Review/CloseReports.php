<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Events\TranslationRejected;
use LonelyLights\Prosetta\Models\TranslationReport;

/** A reviewer acting on a reported string answers the reports: approving accepts them, rejecting dismisses them. */
final readonly class CloseReports {
    public function __construct(private Reports $reports) {}

    public function handle(TranslationApproved|TranslationRejected $event): void {
        $translation = $event->translation;

        $this->reports->close(
            (int) $translation->key_id,
            $translation->locale,
            $event instanceof TranslationApproved ? TranslationReport::ACCEPTED : TranslationReport::DISMISSED,
            $event->by,
        );
    }
}
