<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\Translation;

final class TranslationDrafted {
    public function __construct(public readonly Translation $translation) {}
}
