<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/** Laravel's ":name" replacement tokens. Case matters: :name, :Name and :NAME format differently. */
final class Placeholders {
    private const PATTERN = '/:[A-Za-z_][A-Za-z0-9_]*/';

    /** @return list<string> */
    public static function extract(string $value): array {
        preg_match_all(self::PATTERN, $value, $matches);
        $tokens = $matches[0];
        sort($tokens);

        return $tokens;
    }

    /** @return list<string> */
    public static function unique(string $value): array {
        return array_values(array_unique(self::extract($value)));
    }
}
