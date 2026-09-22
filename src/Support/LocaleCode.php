<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** Locale codes are stored exactly as folders name them; these helpers read them loosely. */
final class LocaleCode {
    public static function language(string $code): string {
        return strtolower((string) (preg_split('/[-_]/', $code)[0] ?? $code));
    }

    public static function isVariantOf(string $code, string $of): bool {
        return $code !== $of && self::language($code) === self::language($of);
    }
}
