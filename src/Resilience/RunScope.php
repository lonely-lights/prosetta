<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Resilience;

/** What one translate() call covered, so a suspended run can be queued again exactly. */
final readonly class RunScope {
    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys key references
     */
    public function __construct(
        public array $locales,
        public array $namespaces,
        public array $keys,
        public bool $force = false,
    ) {}

    public function id(): string {
        $sorted = fn (array $values) => (function () use ($values) {
            sort($values);

            return $values;
        })();

        return sha1((string) json_encode([$sorted($this->locales), $sorted($this->namespaces), $sorted($this->keys), $this->force]));
    }

    /** @return array{locales: list<string>, namespaces: list<string>, keys: list<string>, force: bool} */
    public function toArray(): array {
        return ['locales' => $this->locales, 'namespaces' => $this->namespaces, 'keys' => $this->keys, 'force' => $this->force];
    }

    /** @param array{locales?: list<string>, namespaces?: list<string>, keys?: list<string>, force?: bool} $data */
    public static function fromArray(array $data): self {
        return new self(
            array_values($data['locales'] ?? []),
            array_values($data['namespaces'] ?? []),
            array_values($data['keys'] ?? []),
            (bool) ($data['force'] ?? false),
        );
    }
}
