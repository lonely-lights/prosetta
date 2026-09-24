<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

/**
 * Derives a variant of the source language with no AI: each listed word is
 * swapped for its replacement (color → colour), whole words only, keeping the
 * original's capitalization. Placeholders, HTML tags and URLs are left as
 * they are, so ":color" or class="center" never change.
 */
final class WordReplacer {
    private const string PROTECTED = '~(:[A-Za-z_][A-Za-z0-9_]*|<[^>]*>|https?://\S+)~u';

    /** @param list<array{from: string, to: string}> $replacements */
    public static function apply(string $text, array $replacements): string {
        $map = [];

        foreach ($replacements as $replacement) {
            $map[mb_strtolower($replacement['from'])] = $replacement['to'];
        }

        if ($map === []) {
            return $text;
        }

        $words = array_keys($map);
        # Longest First, so a Longer Listed Phrase Wins Over a Word Inside It
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/\b('.implode('|', array_map(fn (string $word) => preg_quote($word, '/'), $words)).')\b/iu';
        $parts = preg_split(self::PROTECTED, $text, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $index => $part) {
            # Odd Parts Are the Protected Ones the Split Captured
            if ($index % 2 === 0) {
                $parts[$index] = preg_replace_callback($pattern, fn (array $match) => self::cased($match[0], $map[mb_strtolower($match[0])]), $part) ?? $part;
            }
        }

        return implode('', $parts);
    }

    /** The replacement, capitalized the way the matched word was. */
    private static function cased(string $matched, string $replacement): string {
        return match (true) {
            mb_strlen($matched) > 1 && $matched === mb_strtoupper($matched) => mb_strtoupper($replacement),
            mb_substr($matched, 0, 1) === mb_strtoupper(mb_substr($matched, 0, 1)) => mb_strtoupper(mb_substr($replacement, 0, 1)).mb_substr($replacement, 1),
            default => $replacement,
        };
    }
}
