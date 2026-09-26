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
        # Only a Person Answers a Report: the Cycle's Approvals and Confirmations Leave It Open
        if ($event->by === null) {
            return;
        }

        $translation = $event->translation;

        # An Approved String Carries No "Reported" Warning Forward
        if ($event instanceof TranslationApproved && collect($translation->issues ?? [])->contains(fn (array $issue) => ($issue['code'] ?? '') === 'reported')) {
            $translation->forceFill(['issues' => array_values(array_filter($translation->issues, fn (array $issue) => ($issue['code'] ?? '') !== 'reported')) ?: null])->save();
        }

        $this->reports->close(
            $translation->key_id,
            $translation->locale,
            $event instanceof TranslationApproved ? TranslationReport::ACCEPTED : TranslationReport::DISMISSED,
            $event->by,
        );
    }
}
