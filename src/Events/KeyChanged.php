<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\TranslationKey;

final class KeyChanged {
    public function __construct(
        public readonly TranslationKey $key,
        public readonly string $previousValue,
    ) {}
}
