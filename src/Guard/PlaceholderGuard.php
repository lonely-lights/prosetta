<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * Checks a translation against its source: placeholders (exact case),
 * plural segments and ranges, and HTML tags. Runs on every AI result, every
 * manual edit and every imported value.
 */
final class PlaceholderGuard {
    private const RANGE = '/^\s*[\{\[][-?\d|*,\.*]*[\}\]]/';

    /** @return list<Issue> */
    public function check(string $source, string $candidate, string $locale): array {
        if (trim($source) !== '' && trim($candidate) === '') {
            return [Issue::error('empty_value', 'The translation is empty.')];
        }

        return [
            ...$this->placeholders($source, $candidate),
            ...$this->plurals($source, $candidate, $locale),
            ...$this->html($source, $candidate),
        ];
    }

    /** @return list<Issue> */
    private function placeholders(string $source, string $candidate): array {
        $expected = Placeholders::unique($source);
        $actual = Placeholders::unique($candidate);
        $issues = [];

        foreach (array_diff($expected, $actual) as $token) {
            $recased = array_values(array_filter($actual, fn (string $found) => $found !== $token && strcasecmp($found, $token) === 0));

            $issues[] = $recased !== []
                ? Issue::error('placeholder_case', "Placeholder $token changed case to {$recased[0]}; Laravel treats case as formatting.")
                : Issue::error('placeholder_missing', "Placeholder $token is missing.");
        }

        $expectedLower = array_map('strtolower', $expected);

        foreach (array_diff($actual, $expected) as $token) {
            if (! in_array(strtolower($token), $expectedLower, true)) {
                $issues[] = Issue::error('placeholder_unexpected', "Placeholder $token is not in the source.");
            }
        }

        if ($issues === [] && ! str_contains($source, '|') && Placeholders::extract($source) !== Placeholders::extract($candidate)) {
            $issues[] = Issue::warning('placeholder_count', 'A placeholder appears a different number of times than in the source.');
        }

        return $issues;
    }

    /** @return list<Issue> */
    private function plurals(string $source, string $candidate, string $locale): array {
        if (! str_contains($source, '|')) {
            return [];
        }

        $sourceSegments = explode('|', $source);
        $candidateSegments = explode('|', $candidate);
        $ranges = $this->ranges($sourceSegments);

        if ($ranges !== []) {
            $missing = array_diff($ranges, $this->ranges($candidateSegments));

            return $missing === [] ? [] : [Issue::error('plural_range_missing', 'Plural ranges missing: '.implode(', ', $missing).'.')];
        }

        $forms = PluralForms::count($locale);
        $count = count($candidateSegments);

        if ($count === 1 && $forms > 1) {
            return [Issue::error('plural_missing', "The source has plural forms; $locale needs up to $forms.")];
        }

        if ($count > $forms) {
            return [Issue::error('plural_segments_excess', "$count plural segments, but $locale has only $forms forms.")];
        }

        if ($count !== count($sourceSegments)) {
            return [Issue::warning('plural_segments_differ', "$count plural segments where the source has ".count($sourceSegments).'.')];
        }

        return [];
    }

    /**
     * @param list<string> $segments
     * @return list<string>
     */
    private function ranges(array $segments): array {
        $ranges = [];

        foreach ($segments as $segment) {
            if (preg_match(self::RANGE, $segment, $match) === 1) {
                $ranges[] = trim($match[0]);
            }
        }

        return $ranges;
    }

    /** @return list<Issue> */
    private function html(string $source, string $candidate): array {
        $expected = $this->tags($source);

        if ($expected === [] || $expected === $this->tags($candidate)) {
            return [];
        }

        return [Issue::error('html_mismatch', 'HTML tags differ from the source; expected '.implode(' ', $expected).'.')];
    }

    /** @return list<string> */
    private function tags(string $value): array {
        preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)/', $value, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => $match[1].strtolower($match[2]), $matches);
    }
}
