<?php

namespace LonelyLights\Prosetta\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LonelyLights\Prosetta\Models\TranslationFile;

/**
 * Event fired when a new translation file is created in the database.
 *
 * @package LonelyLights\Prosetta\Events
 */
class TranslationFileCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The translation file that was created.
     *
     * @var TranslationFile
     */
    public TranslationFile $file;

    /**
     * Create a new event instance.
     *
     * @param TranslationFile $file
     */
    public function __construct(TranslationFile $file)
    {
        $this->file = $file;
    }
}
