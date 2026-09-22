<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\Translation;

final class TranslationRejected {
    public function __construct(
        public readonly Translation $translation,
        public readonly ?string $by,
    ) {}
}
