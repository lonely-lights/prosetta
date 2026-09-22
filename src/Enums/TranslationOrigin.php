<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum TranslationOrigin: string {
    case Manual = 'manual';
    case Ai = 'ai';
    case Imported = 'imported';
}
