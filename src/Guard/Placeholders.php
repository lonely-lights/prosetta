<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * Laravel's ":name" replacement tokens, plus any the host lists in
 * prosetta.placeholders.patterns (e.g. a "[@]" it swaps for a name).
 * Case matters: :name, :Name and :NAME format differently.
 */
final readonly class Placeholders {
    private const string PATTERN = '/:[A-Za-z_][A-Za-z0-9_]*/';

    /** @return list<string> */
    public static function extract(string $value): array {
        $tokens = [];

        foreach ([self::PATTERN, ...self::hostPatterns()] as $pattern) {
            # A Malformed Host Pattern Matches Nothing Rather Than Breaking Every Check
            if (@preg_match_all($pattern, $value, $matches) !== false) {
                array_push($tokens, ...$matches[0]);
            }
        }

        sort($tokens);

        return $tokens;
    }

    /** @return list<string> */
    private static function hostPatterns(): array {
        $patterns = config('prosetta.placeholders.patterns', []);

        return is_array($patterns) ? array_values(array_filter($patterns, 'is_string')) : [];
    }

    /** @return list<string> */
    public static function unique(string $value): array {
        return array_values(array_unique(self::extract($value)));
    }
}
