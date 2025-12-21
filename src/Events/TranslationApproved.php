<?php

namespace LonelyLights\Prosetta\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationReview;

/**
 * Event fired when a translation is approved during review.
 *
 * @package LonelyLights\Prosetta\Events
 */
class TranslationApproved {
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The translation that was approved.
     *
     * @var Translation
     */
    public Translation $translation;

    /**
     * The review record for this approval.
     *
     * @var TranslationReview
     */
    public TranslationReview $review;

    /**
     * The ID of the user who approved the translation.
     *
     * @var int|null
     */
    public ?int $reviewerId;

    /**
     * Create a new event instance.
     *
     * @param Translation $translation
     * @param TranslationReview $review
     * @param int|null $reviewerId
     */
    public function __construct(Translation $translation, TranslationReview $review, ?int $reviewerId = null) {
        $this->translation = $translation;
        $this->review = $review;
        $this->reviewerId = $reviewerId;
    }
}
