<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

use Illuminate\Translation\MessageSelector;
use LonelyLights\Prosetta\Support\LocaleCode;

/** How many plural forms a language has, derived from Laravel's own plural rules. */
final class PluralForms {
    private const SAMPLES = [1.5, 1000, 1001, 1002, 1011, 1021, 1100];

    public static function count(string $locale): int {
        $selector = new MessageSelector;
        $language = LocaleCode::language($locale);
        $highest = 0;

        foreach ([...range(0, 200), ...self::SAMPLES] as $number) {
            $highest = max($highest, $selector->getPluralIndex($language, $number));
        }

        return $highest + 1;
    }
}
