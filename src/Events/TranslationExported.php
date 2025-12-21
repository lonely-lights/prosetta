<?php

namespace LonelyLights\Prosetta\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LonelyLights\Prosetta\Models\TranslationFile;

/**
 * Event fired when translations are exported to a language file.
 *
 * @package LonelyLights\Prosetta\Events
 */
class TranslationExported {
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The translation file that was exported.
     *
     * @var TranslationFile
     */
    public TranslationFile $file;

    /**
     * The locale that was exported.
     *
     * @var string
     */
    public string $locale;

    /**
     * The full path to the exported file.
     *
     * @var string
     */
    public string $exportedPath;

    /**
     * The number of keys exported.
     *
     * @var int
     */
    public int $keyCount;

    /**
     * Create a new event instance.
     *
     * @param TranslationFile $file
     * @param string $locale
     * @param string $exportedPath
     * @param int $keyCount
     */
    public function __construct(TranslationFile $file, string $locale, string $exportedPath, int $keyCount) {
        $this->file = $file;
        $this->locale = $locale;
        $this->exportedPath = $exportedPath;
        $this->keyCount = $keyCount;
    }
}
