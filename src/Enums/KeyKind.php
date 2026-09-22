<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

/** "content" is reserved for Eloquent fields (spatie/laravel-translatable), planned after the MVP. */
enum KeyKind: string {
    case File = 'file';
    case Content = 'content';
}
