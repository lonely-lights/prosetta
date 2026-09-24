<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\WorkState;

/**
 * One key's state in one locale, by the same rules the cycle uses. Every
 * review screen reads this, so the pages and the cycle never disagree.
 */
final readonly class Status {
    /** @var list<string> the states a person should look at */
    public const array NEEDS_PERSON = ['draft', 'flagged', 'pending', 'stale', 'held'];

    /**
     * @param array<string, array<string, array{hash: string, count: int}>> $failures from CycleFailures::all()
     * @param array<string, array<string, array{hash: string, count: int}>> $rejections from Rejections::all()
     */
    public static function of(TranslationKey $key, ?Translation $translation, string $locale, array $failures, array $rejections): string {
        if (CycleFailures::capped($failures, $locale, $key) || Rejections::held($rejections, $locale, $key)) {
            return 'held';
        }

        if (WorkState::hasCurrentCandidate($key, $translation)) {
            /** @var Translation $translation */
            if (! in_array($translation->origin, [TranslationOrigin::Ai, TranslationOrigin::Derived], true)) {
                return 'pending';
            }

            return empty($translation->issues) ? 'draft' : 'flagged';
        }

        if (WorkState::isStale($key, $translation)) {
            return 'stale';
        }

        return WorkState::isMissing($key, $translation) ? 'missing' : 'approved';
    }
}
