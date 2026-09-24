<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

/**
 * Classify, diff and measure English edits without AI.
 * Word-level operations (Levenshtein distance, LCS, etc.) for comparing source and translation changes.
 */
final readonly class SourceChange {
    /**
     * Determines if a change is cosmetic (whitespace, quotes, case, dashes, trailing punctuation).
     * Normalizes both strings and compares for equality.
     */
    public static function isCosmetic(string $old, string $new): bool {
        return self::normalize($old) === self::normalize($new);
    }

    /**
     * Produces a word-level diff using LCS.
     * Removed words appear as [-word-], added words as {+word+}.
     * Adjacent removals/additions are grouped.
     *
     * @example diff('These credentials do not match our records.', 'These details do not match our records.')
     *          => 'These [-credentials-] {+details+} do not match our records.'
     */
    public static function diff(string $old, string $new): string {
        $oldWords = self::wordArray($old);
        $newWords = self::wordArray($new);

        $lcs = self::computeLCS($oldWords, $newWords);
        $diff = self::buildDiff($oldWords, $newWords, $lcs);

        return implode(' ', $diff);
    }

    /**
     * Measures how much more the translation changed than the English.
     * Returns: normalized translation word edit distance / normalized source word edit distance.
     * Each distance is divided by the longer word count on its side.
     * Normalized distances are then compared (with a floor of 0.01 for division).
     */
    public static function ratio(
        string $oldSource,
        string $newSource,
        string $oldTranslation,
        string $newTranslation,
    ): float {
        $sourceOldWords = self::wordArray($oldSource);
        $sourceNewWords = self::wordArray($newSource);
        $translationOldWords = self::wordArray($oldTranslation);
        $translationNewWords = self::wordArray($newTranslation);

        $sourceDistance = self::levenshteinDistance($sourceOldWords, $sourceNewWords);
        $translationDistance = self::levenshteinDistance($translationOldWords, $translationNewWords);

        $sourceMaxLen = max(count($sourceOldWords), count($sourceNewWords));
        $translationMaxLen = max(count($translationOldWords), count($translationNewWords));

        $sourceNormalized = $sourceMaxLen > 0 ? $sourceDistance / $sourceMaxLen : 0;
        $translationNormalized = $translationMaxLen > 0 ? $translationDistance / $translationMaxLen : 0;

        $denominator = max($sourceNormalized, 0.01);
        return $translationNormalized / $denominator;
    }

    /**
     * Computes the word-level edit distance (Levenshtein).
     */
    public static function changedWords(string $old, string $new): int {
        $oldWords = self::wordArray($old);
        $newWords = self::wordArray($new);

        return self::levenshteinDistance($oldWords, $newWords);
    }

    /**
     * Normalize a string for cosmetic comparison.
     * - lowercase with mb_strtolower
     * - curly quotes → straight (' → ', " → ")
     * - dashes → hyphen (— – → -)
     * - collapse whitespace
     * - trim trailing punctuation (.!?…:;) and whitespace
     */
    private static function normalize(string $text): string {
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Replace curly quotes with straight quotes
        $text = str_replace(["\u{2018}", "\u{2019}"], "'", $text); // U+2018, U+2019
        $text = str_replace(["\u{201C}", "\u{201D}"], '"', $text); // U+201C, U+201D

        // Replace dashes with hyphen
        $text = str_replace(['—', '–'], '-', $text); // U+2014 (em dash), U+2013 (en dash)

        // Collapse whitespace
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        // Trim trailing punctuation and whitespace
        return preg_replace('/[\s.!?…:;]+$/u', '', $text) ?? '';
    }

    /**
     * Split text into words using whitespace as delimiter.
     * For scripts without spaces (Han, Hiragana, Katakana, Thai, Lao, Khmer, Myanmar),
     * split each character individually while keeping runs of other characters together.
     *
     * @return list<string>
     */
    private static function wordArray(string $text): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // First split on whitespace
        $tokens = preg_split('/\s+/u', $text);
        if ($tokens === false) {
            return [];
        }

        $words = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            // Split tokens containing CJK/Thai/Lao/Khmer/Myanmar characters
            // Keep runs of other characters together (Latin, digits, punctuation, placeholders)
            $tokenWords = self::splitScriptTokens($token);
            $words = array_merge($words, $tokenWords);
        }

        return $words;
    }

    /**
     * Split a token into sub-tokens, separating CJK and other script characters.
     * Characters from \p{Han}, \p{Hiragana}, \p{Katakana}, \p{Thai}, \p{Lao}, \p{Khmer}, \p{Myanmar}
     * each become their own token. Runs of other characters stay together.
     *
     * @return list<string>
     */
    private static function splitScriptTokens(string $token): array {
        // Use capturing group to keep matched script characters
        $scriptPattern = '/(\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Thai}|\p{Lao}|\p{Khmer}|\p{Myanmar})/u';

        // Split the token at each script character, keeping the delimiters
        $parts = preg_split($scriptPattern, $token, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return [$token];
        }

        $result = [];
        foreach ($parts as $part) {
            // Filter out empty strings
            if ($part !== '' && $part !== null) {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * Compute the Longest Common Subsequence of two word arrays.
     * Returns a 2D array where lcs[i][j] = length of LCS for old[0..i-1] and new[0..j-1].
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return array<int, array<int, int>>
     */
    private static function computeLCS(array $old, array $new): array {
        $m = count($old);
        $n = count($new);

        // Initialize DP table
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        // Fill DP table
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($old[$i - 1] === $new[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        return $lcs;
    }

    /**
     * Build a word-level diff from LCS.
     * Walks the LCS table backward to reconstruct the diff.
     * Kept words are returned plain, removed words as [-word-], added words as {+word+}.
     * Adjacent removals or additions are grouped into single brackets.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @param array<int, array<int, int>> $lcs
     * @return list<string>
     */
    private static function buildDiff(array $old, array $new, array $lcs): array {
        $m = count($old);
        $n = count($new);
        $diff = [];

        $i = $m;
        $j = $n;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                // Match: add the word
                array_unshift($diff, $old[$i - 1]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i == 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                // Insertion: word added in new
                array_unshift($diff, "{+{$new[$j - 1]}+}");
                $j--;
            } else {
                // Deletion: word removed from old
                array_unshift($diff, "[-{$old[$i - 1]}-]");
                $i--;
            }
        }

        // Group adjacent removed/added words
        return self::groupDiff($diff);
    }

    /**
     * Group adjacent removed or added words in the diff array.
     * Extracts words from brackets, groups them, and re-brackets the group.
     *
     * @param list<string> $diff
     * @return list<string>
     */
    private static function groupDiff(array $diff): array {
        if (empty($diff)) {
            return [];
        }

        $grouped = [];
        $currentWords = [];
        $type = null; // 'removed', 'added', or null for normal words

        foreach ($diff as $item) {
            if (preg_match('/^\[-(.+)-\]$/', $item, $matches)) {
                // Removed word - extract the content
                $word = $matches[1];
                if ($type === 'removed') {
                    // Continue accumulating removed words
                    $currentWords[] = $word;
                } else {
                    // Flush any previous group
                    if (!empty($currentWords)) {
                        $grouped[] = self::formatGroupedDiff($currentWords, $type);
                        $currentWords = [];
                    }
                    // Start new removed group
                    $currentWords[] = $word;
                    $type = 'removed';
                }
            } elseif (preg_match('/^\{\+(.+)\+\}$/', $item, $matches)) {
                // Added word - extract the content
                $word = $matches[1];
                if ($type === 'added') {
                    // Continue accumulating added words
                    $currentWords[] = $word;
                } else {
                    // Flush any previous group
                    if (!empty($currentWords)) {
                        $grouped[] = self::formatGroupedDiff($currentWords, $type);
                        $currentWords = [];
                    }
                    // Start new added group
                    $currentWords[] = $word;
                    $type = 'added';
                }
            } else {
                // Normal word
                if (!empty($currentWords)) {
                    $grouped[] = self::formatGroupedDiff($currentWords, $type);
                    $currentWords = [];
                    $type = null;
                }
                $grouped[] = $item;
            }
        }

        // Flush any remaining group
        if (!empty($currentWords)) {
            $grouped[] = self::formatGroupedDiff($currentWords, $type);
        }

        return $grouped;
    }

    /**
     * Format a group of words with appropriate brackets.
     *
     * @param list<string> $words
     * @param string|null $type 'removed' or 'added'
     */
    private static function formatGroupedDiff(array $words, ?string $type): string {
        $content = implode(' ', $words);
        if ($type === 'removed') {
            return "[-$content-]";
        } elseif ($type === 'added') {
            return "{+$content+}";
        }
        return $content;
    }

    /**
     * Compute the Levenshtein distance between two word arrays.
     * Uses dynamic programming.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    private static function levenshteinDistance(array $old, array $new): int {
        $m = count($old);
        $n = count($new);

        // Initialize DP table
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        // Initialize first row and column
        for ($i = 0; $i <= $m; $i++) {
            $dp[$i][0] = $i;
        }
        for ($j = 0; $j <= $n; $j++) {
            $dp[0][$j] = $j;
        }

        // Fill DP table
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($old[$i - 1] === $new[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1];
                } else {
                    $dp[$i][$j] = 1 + min($dp[$i - 1][$j], $dp[$i][$j - 1], $dp[$i - 1][$j - 1]);
                }
            }
        }

        return $dp[$m][$n];
    }
}
