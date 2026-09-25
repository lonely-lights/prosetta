<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Events\TranslationApproved;

/** A content approval must show at once, so its folder's cache goes. */
final readonly class ForgetApprovedContent {
    public function __construct(private ContentTranslations $translations) {}

    public function handle(TranslationApproved $event): void {
        $file = $event->translation->key->file;

        if ($file->format === FileFormat::Database) {
            $this->translations->forget($file->group);
        }
    }
}
