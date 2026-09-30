<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * Laravel's ":name" replacement tokens, plus any the host lists in
 * prosetta.placeholders.patterns (e.g. a "[@]" it swaps for a name) and the
 * words in prosetta.placeholders.terms (product and brand names). Case
 * matters: :name, :Name and :NAME format differently.
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
        $terms = config('prosetta.placeholders.terms', []);

        return [
            ...(is_array($patterns) ? array_values(array_filter($patterns, 'is_string')) : []),
            ...(is_array($terms) ? array_map(self::termPattern(...), array_values(array_filter($terms, fn ($term) => is_string($term) && $term !== ''))) : []),
        ];
    }

    /**
     * A whole-word, any-case match, so "UNDAUNTED" in a heading counts and
     * "Undauntedly" does not; the source's own spelling is what must be kept.
     */
    private static function termPattern(string $term): string {
        return '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
    }

    /** @return list<string> */
    public static function unique(string $value): array {
        return array_values(array_unique(self::extract($value)));
    }
}
