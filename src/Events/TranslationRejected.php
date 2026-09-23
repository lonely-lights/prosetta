<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\Translation;

final readonly class TranslationRejected {
    public function __construct(
        public Translation $translation,
        public ?string $by,
    ) {}
}
