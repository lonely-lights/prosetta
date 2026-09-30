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

    /** Scripts a term's edge letter is matched against; any other letter falls back to \p{L}. */
    private const array SCRIPTS = ['Latin', 'Cyrillic', 'Greek', 'Armenian', 'Georgian', 'Hebrew', 'Arabic', 'Devanagari', 'Bengali', 'Thai', 'Hangul', 'Hiragana', 'Katakana', 'Han'];

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
     * Only letters of the term's own script break the word: Japanese and
     * Korean write "Undauntedは" with no space, and that is still the name.
     */
    private static function termPattern(string $term): string {
        # An Edge That Isn't a Letter or Digit ("C++") Needs No Boundary on That Side
        $edge = fn (string $char): ?string => preg_match('/^[\p{L}\p{N}]$/u', $char) === 1 ? '[\p{'.self::script($char).'}\p{N}]' : null;
        $before = $edge(mb_substr($term, 0, 1));
        $after = $edge(mb_substr($term, -1));

        return '/'.($before === null ? '' : "(?<!$before)").preg_quote($term, '/').($after === null ? '' : "(?!$after)").'/iu';
    }

    /** The Unicode script a character belongs to, or L (any letter) for one outside the common scripts. */
    private static function script(string $char): string {
        foreach (self::SCRIPTS as $script) {
            if (preg_match('/^\p{'.$script.'}$/u', $char) === 1) {
                return $script;
            }
        }

        return 'L';
    }

    /** @return list<string> */
    public static function unique(string $value): array {
        return array_values(array_unique(self::extract($value)));
    }
}
