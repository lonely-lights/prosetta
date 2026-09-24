<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\State;
use Throwable;

/**
 * How often the provider failed to translate a key in a cycle run (refused
 * it, returned no value, or rejected its batch), kept in prosetta_state under
 * cycle.failures as locale => key id => {hash, count, run, ref}. Once a key
 * has failed LIMIT times from the same English, the cycle stops sending it
 * and reports it instead; an English edit starts the count again, and a
 * successful draft or a person's value removes the entry.
 */
final readonly class CycleFailures {
    public const string KEY = 'cycle.failures';

    public const int LIMIT = 3;

    /**
     * Counts this run's failures and clears the entries of keys it drafted, in one locked write.
     *
     * @param list<TranslationKey> $failed keys that failed in a cycle run
     * @param list<int> $drafted ids of keys that got a draft, in any run
     * @throws Throwable when the transaction fails
     */
    public function settle(string $locale, array $failed, array $drafted, ?string $runId): void {
        # Most Runs Fail Nothing and Draft Nothing Counted Before: Skip the Locked Write
        if ($failed === [] && ! $this->anyStored($locale, $drafted)) {
            return;
        }

        State::update(self::KEY, function (mixed $stored) use ($locale, $failed, $drafted, $runId): ?array {
            $map = is_array($stored) ? $stored : [];

            foreach ($drafted as $id) {
                unset($map[$locale][(string) $id]);
            }

            foreach ($failed as $key) {
                $id = (string) $key->getKey();
                $entry = $map[$locale][$id] ?? null;
                $count = is_array($entry) && ($entry['hash'] ?? null) === $key->source_hash ? (int) ($entry['count'] ?? 0) : 0;
                $map[$locale][$id] = ['hash' => $key->source_hash, 'count' => $count + 1, 'run' => $runId, 'ref' => $key->ref()->toString()];
            }

            if (($map[$locale] ?? null) === []) {
                unset($map[$locale]);
            }

            return $map === [] ? null : $map;
        }, []);
    }

    /**
     * Forgets a key's failures in one locale, once a person has given it a value.
     * @throws Throwable when the transaction fails
     */
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

    /** @return array<string, array<string, array{hash: string, count: int, run: string|null, ref: string}>> */
    public function all(): array {
        $stored = State::get(self::KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Whether the cycle should stop sending this key: it failed LIMIT times or more from its current English.
     *
     * @param array<string, array<string, array{hash: string, count: int, run: string|null, ref: string}>> $all from all()
     */
    public static function capped(array $all, string $locale, TranslationKey $key): bool {
        $entry = $all[$locale][(string) $key->getKey()] ?? null;

        return is_array($entry) && ($entry['hash'] ?? null) === $key->source_hash && (int) ($entry['count'] ?? 0) >= self::LIMIT;
    }

    /**
     * The keys that failed in this run, for the cycle's report.
     *
     * @return list<string> "{locale} {ref} (…)"
     */
    public function failedIn(string $runId): array {
        $lines = [];

        foreach ($this->all() as $locale => $entries) {
            foreach ($entries as $entry) {
                if (($entry['run'] ?? null) === $runId) {
                    $lines[] = sprintf('%s %s (translation failed, %d of %d tries before the cycle stops sending it)', $locale, $entry['ref'], $entry['count'], self::LIMIT);
                }
            }
        }

        return $lines;
    }

    /** @param list<int> $drafted */
    private function anyStored(string $locale, array $drafted): bool {
        if ($drafted === []) {
            return false;
        }

        $entries = $this->all()[$locale] ?? [];

        foreach ($drafted as $id) {
            if (isset($entries[(string) $id])) {
                return true;
            }
        }

        return false;
    }
}
