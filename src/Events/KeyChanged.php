<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\TranslationKey;

final readonly class KeyChanged {
    public function __construct(
        public TranslationKey $key,
        public string $previousValue,
    ) {}
}
