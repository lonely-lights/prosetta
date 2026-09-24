<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\State;
use Throwable;

/**
 * How often reviewers rejected a key's candidate in one locale, kept in
 * prosetta_state under review.rejections as locale => key id => {hash, count}.
 * Once a key is rejected LIMIT times from the same English, the cycle stops
 * drafting it and waits for a person; an English edit starts the count again,
 * and a person's own value or an approval clears it.
 */
final readonly class Rejections {
    public const string KEY = 'review.rejections';

    public const int LIMIT = 2;

    /** @throws Throwable when the transaction fails */
    public function record(Translation $translation): void {
        $key = $translation->key;

        State::update(self::KEY, function (mixed $stored) use ($translation, $key): array {
            $map = is_array($stored) ? $stored : [];
            $entry = $map[$translation->locale][(string) $key->getKey()] ?? null;
            $count = is_array($entry) && ($entry['hash'] ?? null) === $key->source_hash ? (int) ($entry['count'] ?? 0) : 0;
            $map[$translation->locale][(string) $key->getKey()] = ['hash' => $key->source_hash, 'count' => $count + 1];

            return $map;
        }, []);
    }

    /** @throws Throwable when the transaction fails */
    public function clear(string $locale, int $keyId): void {
        if (! isset($this->all()[$locale][(string) $keyId])) {
            return;
        }

        State::update(self::KEY, function (mixed $stored) use ($locale, $keyId): ?array {
            $map = is_array($stored) ? $stored : [];
            unset($map[$locale][(string) $keyId]);

            if (($map[$locale] ?? null) === []) {
                unset($map[$locale]);
            }

            return $map === [] ? null : $map;
        }, []);
    }

    /** @return array<string, array<string, array{hash: string, count: int}>> */
    public function all(): array {
        $stored = State::get(self::KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, array<string, array{hash: string, count: int}>> $all from all() */
    public static function held(array $all, string $locale, TranslationKey $key): bool {
        $entry = $all[$locale][(string) $key->getKey()] ?? null;

        return is_array($entry) && ($entry['hash'] ?? null) === $key->source_hash && (int) ($entry['count'] ?? 0) >= self::LIMIT;
    }
}
