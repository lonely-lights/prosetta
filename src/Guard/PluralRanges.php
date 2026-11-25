<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * Reads the range markers on plural segments ({0}, {1}, [2,*], [3,10]) as sets of
 * whole numbers, the way Laravel's MessageSelector applies them, so a translation can
 * be checked for which counts it covers rather than for the exact markers it repeats.
 * Arabic writes "{0}|{1}|{2}|[3,10]|[11,*]" where English writes "{0}|{1}|[2,*]";
 * both cover every count.
 */
final readonly class PluralRanges {
    private const string MARKER = '/^\s*([\{\[])([^\[\]\{\}]*)[\}\]]/';

    /** The marker at the start of a segment, as written ("{1}", "[2,*]"), or null. */
    public static function marker(string $segment): ?string {
        return preg_match(self::MARKER, $segment, $match) === 1 ? trim($match[0]) : null;
    }

    /**
     * The whole numbers a marker covers, as an inclusive [from, to] pair; "*" is
     * unbounded. A marker Laravel would never match a whole number with is null.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function interval(string $marker): ?array {
        if (preg_match(self::MARKER, $marker, $match) !== 1) {
            return null;
        }

        $condition = trim($match[2]);

        if (! str_contains($condition, ',')) {
            return is_numeric($condition) && (int) $condition == $condition ? [(int) $condition, (int) $condition] : null;
        }

        [$from, $to] = array_map(trim(...), explode(',', $condition, 2));
        $low = $from === '*' ? PHP_INT_MIN : (is_numeric($from) ? (int) ceil((float) $from) : null);
        $high = $to === '*' ? PHP_INT_MAX : (is_numeric($to) ? (int) floor((float) $to) : null);

        return $low === null || $high === null || $low > $high ? null : [$low, $high];
    }

    /** Whether a marker names one exact count ({1}, {2}), whose form may spell the number out. */
    public static function isExact(string $marker): bool {
        $interval = self::interval($marker);

        return $interval !== null && $interval[0] === $interval[1];
    }

    /**
     * Whether every segment carries a marker.
     *
     * @param list<string> $segments
     */
    public static function allMarked(array $segments): bool {
        return $segments !== [] && array_filter($segments, fn (string $segment) => self::marker($segment) === null) === [];
    }

    /**
     * Whether two intervals share at least one number.
     *
     * @param array{0: int, 1: int} $first
     * @param array{0: int, 1: int} $second
     */
    public static function overlaps(array $first, array $second): bool {
        return $first[0] <= $second[1] && $second[0] <= $first[1];
    }

    /**
     * The parts of $needed that none of $covering reaches.
     *
     * @param array{0: int, 1: int} $needed
     * @param list<array{0: int, 1: int}> $covering
     * @return list<array{0: int, 1: int}>
     */
    public static function uncovered(array $needed, array $covering): array {
        $remaining = [$needed];

        foreach ($covering as [$low, $high]) {
            $next = [];

            foreach ($remaining as [$from, $to]) {
                if ($high < $from || $low > $to) {
                    $next[] = [$from, $to];

                    continue;
                }

                if ($low > $from) {
                    $next[] = [$from, $low - 1];
                }

                if ($high < $to) {
                    $next[] = [$high + 1, $to];
                }
            }

            $remaining = $next;
        }

        return $remaining;
    }

    /**
     * Writes an interval back as a marker.
     *
     * @param array{0: int, 1: int} $interval
     */
    public static function describe(array $interval): string {
        [$low, $high] = $interval;

        if ($low === $high) {
            return '{'.$low.'}';
        }

        return '['.($low === PHP_INT_MIN ? '*' : $low).','.($high === PHP_INT_MAX ? '*' : $high).']';
    }
}
