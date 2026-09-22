<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/** One problem with a translation. Only errors block approval and export. */
final readonly class Issue {
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $message,
    ) {}

    public static function error(string $code, string $message): self {
        return new self($code, Severity::Error, $message);
    }

    public static function warning(string $code, string $message): self {
        return new self($code, Severity::Warning, $message);
    }

    public function isBlocking(): bool {
        return $this->severity === Severity::Error;
    }

    /** @return array{code: string, severity: string, message: string} */
    public function toArray(): array {
        return ['code' => $this->code, 'severity' => $this->severity->value, 'message' => $this->message];
    }

    /** @param array{code: string, severity: string, message: string} $data */
    public static function fromArray(array $data): self {
        return new self($data['code'], Severity::from($data['severity']), $data['message']);
    }

    /**
     * @param list<self> $issues
     * @return list<array{code: string, severity: string, message: string}>|null
     */
    public static function store(array $issues): ?array {
        return $issues === [] ? null : array_map(fn (self $issue) => $issue->toArray(), $issues);
    }

    /** @param list<array{code: string, severity: string, message: string}>|null $stored */
    public static function anyBlocking(?array $stored): bool {
        foreach ($stored ?? [] as $row) {
            if (($row['severity'] ?? null) === Severity::Error->value) {
                return true;
            }
        }

        return false;
    }
}
