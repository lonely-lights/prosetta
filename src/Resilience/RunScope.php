<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

/** What one translate() call covered, so a suspended run can be queued again exactly. */
final readonly class RunScope {
    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys key references
     * @param int|null $startedAt Unix time the run began; a resumed forced run only re-forces keys last touched before it
     * @param bool $cycle a prosetta:cycle run: resume clears it instead of re-translating, since the next cycle rebuilds its own work
     */
    public function __construct(
        public array $locales,
        public array $namespaces,
        public array $keys,
        public bool $force = false,
        public ?int $startedAt = null,
        public bool $cycle = false,
    ) {}

    /**
     * Leaves out startedAt, so repeated suspensions of the same run merge. A
     * cycle run hashes apart from a manual run over the same locales (and a
     * manual run's id is the same as before the flag existed).
     */
    public function id(): string {
        $sorted = fn (array $values) => (function () use ($values) {
            sort($values);

            return $values;
        })();

        $parts = [$sorted($this->locales), $sorted($this->namespaces), $sorted($this->keys), $this->force];

        return sha1((string) json_encode($this->cycle ? [...$parts, 'cycle'] : $parts));
    }

    /** @return array{locales: list<string>, namespaces: list<string>, keys: list<string>, force: bool, started_at: ?int, cycle: bool} */
    public function toArray(): array {
        return ['locales' => $this->locales, 'namespaces' => $this->namespaces, 'keys' => $this->keys, 'force' => $this->force, 'started_at' => $this->startedAt, 'cycle' => $this->cycle];
    }

    /** @param array{locales?: list<string>, namespaces?: list<string>, keys?: list<string>, force?: bool, started_at?: ?int, cycle?: bool} $data */
    public static function fromArray(array $data): self {
        return new self(
            array_values($data['locales'] ?? []),
            array_values($data['namespaces'] ?? []),
            array_values($data['keys'] ?? []),
            (bool) ($data['force'] ?? false),
            isset($data['started_at']) ? (int) $data['started_at'] : null,
            (bool) ($data['cycle'] ?? false),
        );
    }
}
