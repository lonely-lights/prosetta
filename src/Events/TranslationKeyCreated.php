<?php

namespace LonelyLights\Prosetta\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LonelyLights\Prosetta\Models\TranslationKey;

/**
 * Event fired when a new translation key is created in the database.
 *
 * @package LonelyLights\Prosetta\Events
 */
class TranslationKeyCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The translation key that was created.
     *
     * @var TranslationKey
     */
    public TranslationKey $key;

    /**
     * Create a new event instance.
     *
     * @param TranslationKey $key
     */
    public function __construct(TranslationKey $key)
    {
        $this->key = $key;
    }
}
