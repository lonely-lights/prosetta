<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * Checks a translation against its source: placeholders (exact case),
 * plural segments and ranges, and HTML tags. Runs on every AI result, every
 * manual edit and every imported value.
 */
final readonly class PlaceholderGuard {
    private const string RANGE = '/^\s*[\{\[][-?\d|*,\.*]*[\}\]]/';

    /** @return list<Issue> */
    public function check(string $source, string $candidate, string $locale): array {
        if (trim($source) !== '' && trim($candidate) === '') {
            return [Issue::error('empty_value', 'The translation is empty.')];
        }

        $isPlural = str_contains($source, '|');

        return [
            ...($isPlural ? $this->pluralPlaceholders($source, $candidate) : $this->placeholders($source, $candidate)),
            ...$this->plurals($source, $candidate, $locale),
            ...$this->html($source, $candidate),
        ];
    }

    /** @return list<Issue> */
    private function placeholders(string $source, string $candidate): array {
        $issues = $this->comparePlaceholders(Placeholders::unique($source), Placeholders::unique($candidate), '');

        if ($issues === [] && Placeholders::extract($source) !== Placeholders::extract($candidate)) {
            $issues[] = Issue::warning('placeholder_count', 'A placeholder appears a different number of times than in the source.');
        }

        return $issues;
    }

    /**
     * Compares a source's placeholders to a candidate's for one segment (or the whole
     * string, for non-plural values). $context is appended to messages, e.g. " from the
     * [2,*] form" or " from segment 2", and left blank for non-plural checks.
     *
     * @param list<string> $expected
     * @param list<string> $actual
     * @return list<Issue>
     */
    private function comparePlaceholders(array $expected, array $actual, string $context): array {
        $issues = [];

        foreach (array_diff($expected, $actual) as $token) {
            $recased = array_values(array_filter($actual, fn (string $found) => $found !== $token && strcasecmp($found, $token) === 0));

            $issues[] = $recased !== []
                ? Issue::error('placeholder_case', "Placeholder $token changed case to $recased[0]$context; Laravel treats case as formatting.")
                : Issue::error('placeholder_missing', "Placeholder $token is missing$context.");
        }

        $expectedLower = array_map('strtolower', $expected);

        foreach (array_diff($actual, $expected) as $token) {
            if (! in_array(strtolower($token), $expectedLower, true)) {
                $issues[] = Issue::error('placeholder_unexpected', "Placeholder $token is not in the source$context.");
            }
        }

        return $issues;
    }

    /**
     * Plural placeholders are checked per segment instead of across the whole string, so a
     * placeholder legitimately absent from one form (e.g. "{1} :n minute") isn't required
     * in another (e.g. "[2,*] :n minutes") and vice versa.
     *
     * @return list<Issue>
     */
    private function pluralPlaceholders(string $source, string $candidate): array {
        $sourceSegments = explode('|', $source);
        $candidateSegments = explode('|', $candidate);

        return $this->ranges($sourceSegments) !== []
            ? $this->rangedPluralPlaceholders($sourceSegments, $candidateSegments)
            : $this->unmarkedPluralPlaceholders($sourceSegments, $candidateSegments);
    }

    /**
     * Matches source and candidate segments by their range marker ({0}, {1}, [2,*], ...).
     * A marker missing from the candidate entirely is already reported by plurals() as
     * plural_range_missing, so it's skipped here to avoid reporting it twice.
     *
     * @param list<string> $sourceSegments
     * @param list<string> $candidateSegments
     * @return list<Issue>
     */
    private function rangedPluralPlaceholders(array $sourceSegments, array $candidateSegments): array {
        $candidateByRange = [];

        foreach ($candidateSegments as $segment) {
            if (preg_match(self::RANGE, $segment, $match) === 1) {
                $candidateByRange[trim($match[0])] = $segment;
            }
        }

        $issues = [];

        foreach ($sourceSegments as $segment) {
            if (preg_match(self::RANGE, $segment, $match) !== 1) {
                continue;
            }

            $range = trim($match[0]);

            if (! array_key_exists($range, $candidateByRange)) {
                continue;
            }

            $issues = [
                ...$issues,
                ...$this->comparePlaceholders(Placeholders::unique($segment), Placeholders::unique($candidateByRange[$range]), " from the $range form"),
            ];
        }

        return $issues;
    }

    /**
     * Without range markers, segment counts legitimately differ between languages (Arabic
     * has 6 forms, zh-CN has 1), so segments are matched positionally nowhere; instead:
     * every candidate placeholder must come from somewhere in the source, a placeholder in
     * EVERY source segment must survive in EVERY candidate segment, and a placeholder in
     * only SOME source segments only needs to survive somewhere in the candidate.
     *
     * @param list<string> $sourceSegments
     * @param list<string> $candidateSegments
     * @return list<Issue>
     */
    private function unmarkedPluralPlaceholders(array $sourceSegments, array $candidateSegments): array {
        $perSourceSegment = array_map(Placeholders::unique(...), $sourceSegments);
        $sourceUnion = array_values(array_unique(array_merge(...$perSourceSegment)));
        $sourceLowerUnion = array_map('strtolower', $sourceUnion);
        $everySegment = array_values(array_intersect(...$perSourceSegment));
        $someSegments = array_values(array_diff($sourceUnion, $everySegment));

        $perCandidateSegment = array_map(Placeholders::unique(...), $candidateSegments);
        $candidateUnion = array_values(array_unique(array_merge(...$perCandidateSegment)));

        $issues = [];

        foreach ($candidateUnion as $token) {
            if (! in_array(strtolower($token), $sourceLowerUnion, true)) {
                $issues[] = Issue::error('placeholder_unexpected', "Placeholder $token is not in the source.");
            }
        }

        foreach ($everySegment as $token) {
            foreach ($perCandidateSegment as $index => $tokens) {
                if (! in_array($token, $tokens, true)) {
                    $segment = $index + 1;
                    $issues[] = Issue::error('placeholder_missing', "Placeholder $token is missing from segment $segment.");
                }
            }
        }

        foreach ($someSegments as $token) {
            if (! in_array($token, $candidateUnion, true)) {
                $issues[] = Issue::error('placeholder_missing', "Placeholder $token is missing.");
            }
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
