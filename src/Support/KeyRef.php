<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

use InvalidArgumentException;
use Stringable;

/**
 * A key in Laravel's own notation: "namespace::group.key", "group.key" for the
 * root lang folder, or "json:Whole sentence." for JSON strings. The group ends
 * at the first dot; groups may contain "/" (nested folders) but never ".".
 */
final readonly class KeyRef implements Stringable {
    public const string ROOT = '*';
    public const string JSON_GROUP = '*';
    private const string JSON_PREFIX = 'json:';

    public function __construct(
        public string $namespace,
        public string $group,
        public string $key,
    ) {}

    public static function parse(string $ref): self {
        if (str_starts_with($ref, self::JSON_PREFIX)) {
            $key = substr($ref, strlen(self::JSON_PREFIX));

            if ($key === '') {
                throw new InvalidArgumentException('A JSON key reference needs the key after "json:".');
            }

            return new self(self::ROOT, self::JSON_GROUP, $key);
        }

        $namespace = self::ROOT;
        $rest = $ref;

        if (str_contains($ref, '::')) {
            [$namespace, $rest] = explode('::', $ref, 2);
        }

        $dot = strpos($rest, '.');

        if ($namespace === '' || $dot === false || $dot === 0 || $dot === strlen($rest) - 1) {
            throw new InvalidArgumentException("[$ref] is not a key reference; expected group.key, namespace::group.key or json:Key.");
        }

        return new self($namespace, substr($rest, 0, $dot), substr($rest, $dot + 1));
    }

    public function isJson(): bool {
        return $this->group === self::JSON_GROUP;
    }

    public function toString(): string {
        if ($this->isJson()) {
            return self::JSON_PREFIX.$this->key;
        }

        $prefix = $this->namespace === self::ROOT ? '' : $this->namespace.'::';

        return $prefix.$this->group.'.'.$this->key;
    }

    public function __toString(): string {
        return $this->toString();
    }
}
