<?php

namespace LonelyLights\Prosetta\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LonelyLights\Prosetta\Models\Translation;

/**
 * Event fired when a translation is flagged for human review.
 *
 * This typically occurs when:
 * - An AI-generated translation has low confidence
 * - A translation is manually flagged for review
 * - A translation value is changed and needs re-verification
 *
 * @package LonelyLights\Prosetta\Events
 */
class TranslationNeedsReview
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The translation that needs review.
     *
     * @var Translation
     */
    public Translation $translation;

    /**
     * The reason the translation needs review.
     *
     * @var string|null
     */
    public ?string $reason;

    /**
     * Create a new event instance.
     *
     * @param Translation $translation
     * @param string|null $reason
     */
    public function __construct(Translation $translation, ?string $reason = null)
    {
        $this->translation = $translation;
        $this->reason = $reason;
    }
}
