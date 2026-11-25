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

        $isPlural = $this->isPlural($source);

        return [
            ...($isPlural ? $this->pluralPlaceholders($source, $candidate) : $this->placeholders($source, $candidate)),
            ...($isPlural ? $this->plurals($source, $candidate, $locale) : $this->literalPipes($source, $candidate)),
            ...$this->html($source, $candidate),
        ];
    }

    /**
     * A "|" means plural forms only when the string also carries range markers
     * or a :count placeholder; otherwise it is literal text (e.g. "Home | :app").
     */
    private function isPlural(string $source): bool {
        return str_contains($source, '|')
            && ($this->ranges(explode('|', $source)) !== [] || str_contains($source, ':count'));
    }

    /** @return list<Issue> */
    private function literalPipes(string $source, string $candidate): array {
        return substr_count($source, '|') === substr_count($candidate, '|')
            ? []
            : [Issue::warning('pipe_count', 'The source contains a literal "|" a different number of times than the translation.')];
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
     * Checks each marked candidate segment against the source segments whose counts it
     * shares, so a language may split or merge ranges (Arabic's {2} and [3,10] both sit
     * inside English's [2,*]). A placeholder found in every one of those source segments
     * must survive; one found in only some of them may; anything else is unexpected. A
     * segment for one exact count ({1}, {2}) may spell the number out instead of :count.
     * Counts left uncovered are reported once, by plurals(), as plural_range_missing.
     *
     * @param list<string> $sourceSegments
     * @param list<string> $candidateSegments
     * @return list<Issue>
     */
    private function rangedPluralPlaceholders(array $sourceSegments, array $candidateSegments): array {
        $sources = [];

        foreach ($sourceSegments as $segment) {
            $marker = PluralRanges::marker($segment);

            if ($marker !== null) {
                $sources[] = ['marker' => $marker, 'interval' => PluralRanges::interval($marker), 'tokens' => Placeholders::unique($segment)];
            }
        }

        $issues = [];

        foreach ($candidateSegments as $segment) {
            $marker = PluralRanges::marker($segment);

            if ($marker === null) {
                continue;
            }

            $interval = PluralRanges::interval($marker);
            $shared = array_values(array_filter($sources, fn (array $source) => $interval !== null && $source['interval'] !== null
                ? PluralRanges::overlaps($interval, $source['interval'])
                : $source['marker'] === $marker));

            if ($shared === []) {
                continue;
            }

            $issues = [
                ...$issues,
                ...$this->compareShared(array_column($shared, 'tokens'), Placeholders::unique($segment), PluralRanges::isExact($marker), " from the $marker form"),
            ];
        }

        return $issues;
    }

    /**
     * @param list<list<string>> $sharedTokens the placeholders of each source segment this candidate segment covers
     * @param list<string> $actual
     * @return list<Issue>
     */
    private function compareShared(array $sharedTokens, array $actual, bool $exact, string $context): array {
        $required = count($sharedTokens) === 1 ? $sharedTokens[0] : array_values(array_intersect(...$sharedTokens));
        $allowed = array_values(array_unique(array_merge(...$sharedTokens)));

        if ($exact) {
            $required = array_values(array_filter($required, fn (string $token) => $token !== ':count'));
        }

        $requiredLower = array_map('strtolower', $required);
        $optionalLower = array_diff(array_map('strtolower', $allowed), $requiredLower);
        $checked = array_values(array_filter($actual, fn (string $token) => ! in_array(strtolower($token), $optionalLower, true)));

        return $this->comparePlaceholders($required, $checked, $context);
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
                $marker = PluralRanges::marker($candidateSegments[$index]);

                if ($token === ':count' && $marker !== null && PluralRanges::isExact($marker)) {
                    continue;
                }

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
            return $this->rangeCoverage($sourceSegments, $candidateSegments);
        }

        if (PluralRanges::allMarked($candidateSegments)) {
            $gaps = PluralRanges::uncovered([0, PHP_INT_MAX], $this->intervals($candidateSegments));

            return $gaps === [] ? [] : [Issue::error('plural_range_missing', 'Plural ranges leave out these counts: '.implode(', ', array_map(PluralRanges::describe(...), $gaps)).'.')];
        }

        $forms = PluralForms::count($locale);
        $count = count($candidateSegments);

        if ($count === 1 && $forms > 1) {
            return [Issue::error('plural_missing', "The source has plural forms; $locale needs up to $forms.")];
        }

        if ($count > $forms) {
            return [Issue::error('plural_segments_excess', "$count plural segments, but $locale has only $forms forms.")];
        }

        if ($count !== count($sourceSegments) && $count !== $forms) {
            return [Issue::warning('plural_segments_differ', "$count plural segments where the source has ".count($sourceSegments).'.')];
        }

        return [];
    }

    /**
     * Every count a source marker covers must be covered by some candidate marker; the
     * markers themselves may differ. A source marker that isn't a readable range still
     * has to appear as written.
     *
     * @param list<string> $sourceSegments
     * @param list<string> $candidateSegments
     * @return list<Issue>
     */
    private function rangeCoverage(array $sourceSegments, array $candidateSegments): array {
        $covering = $this->intervals($candidateSegments);
        $candidateMarkers = $this->ranges($candidateSegments);
        $missing = [];

        foreach ($this->ranges($sourceSegments) as $marker) {
            $interval = PluralRanges::interval($marker);
            $covered = $interval === null
                ? in_array($marker, $candidateMarkers, true)
                : PluralRanges::uncovered($interval, $covering) === [];

            if (! $covered) {
                $missing[] = $marker;
            }
        }

        return $missing === [] ? [] : [Issue::error('plural_range_missing', 'Plural ranges missing: '.implode(', ', $missing).'.')];
    }

    /**
     * @param list<string> $segments
     * @return list<array{0: int, 1: int}>
     */
    private function intervals(array $segments): array {
        $intervals = [];

        foreach ($segments as $segment) {
            $marker = PluralRanges::marker($segment);
            $interval = $marker === null ? null : PluralRanges::interval($marker);

            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        return $intervals;
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
