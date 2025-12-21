<?php

namespace LonelyLights\Prosetta\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LonelyLights\Prosetta\Models\Translation;

/**
 * Event fired when a translation value is updated.
 *
 * @package LonelyLights\Prosetta\Events
 */
class TranslationUpdated {
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The translation that was updated.
     *
     * @var Translation
     */
    public Translation $translation;

    /**
     * The previous value before the update.
     *
     * @var string|null
     */
    public ?string $previousValue;

    /**
     * The new value after the update.
     *
     * @var string
     */
    public string $newValue;

    /**
     * Create a new event instance.
     *
     * @param Translation $translation
     * @param string|null $previousValue
     * @param string $newValue
     */
    public function __construct(Translation $translation, ?string $previousValue, string $newValue) {
        $this->translation = $translation;
        $this->previousValue = $previousValue;
        $this->newValue = $newValue;
    }
}
