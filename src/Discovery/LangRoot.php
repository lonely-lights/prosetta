<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Discovery;

use LonelyLights\Prosetta\Support\KeyRef;

/** A folder holding {locale}/ subfolders of lang files, under one translation namespace. */
final readonly class LangRoot {
    public function __construct(
        public string $namespace,
        public string $path,
    ) {}

    public function isRoot(): bool {
        return $this->namespace === KeyRef::ROOT;
    }
}
