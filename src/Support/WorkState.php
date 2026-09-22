<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;

/** The derived work states. Nothing is stored; everything follows from hashes and statuses. */
final class WorkState {
    /** No approved value and no candidate worth reviewing. */
    public static function isMissing(TranslationKey $key, ?Translation $translation): bool {
        if ($translation === null) {
            return true;
        }

        return $translation->approved_value === null
            && ($translation->value === null || $translation->status === TranslationStatus::Rejected);
    }

    /** The live (approved) value was made from different English than the key has now. */
    public static function isStale(TranslationKey $key, ?Translation $translation): bool {
        return $translation !== null
            && $translation->approved_value !== null
            && $translation->approved_source_hash !== $key->source_hash;
    }

    /** A draft or edit made from the current English is waiting for review. */
    public static function hasCurrentCandidate(TranslationKey $key, ?Translation $translation): bool {
        return $translation !== null
            && $translation->value !== null
            && $translation->source_hash === $key->source_hash
            && in_array($translation->status, [TranslationStatus::Draft, TranslationStatus::NeedsReview], true);
    }

    /** Whether the AI should (re)translate this key for this locale. */
    public static function needsWork(TranslationKey $key, ?Translation $translation): bool {
        if (self::isMissing($key, $translation)) {
            return true;
        }

        if (self::hasCurrentCandidate($key, $translation)) {
            return false;
        }

        if (self::isStale($key, $translation)) {
            return true;
        }

        return $translation->approved_value === null && $translation->source_hash !== $key->source_hash;
    }
}
