<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/** What translation needs to know about a locale, with no database attached. */
final readonly class LocaleDescriptor {
    public function __construct(
        public string $code,
        public string $englishName,
        public string $nativeName,
        public ?string $script = null,
        public bool $rtl = false,
    ) {}

    /** @return array{code: string, englishName: string, nativeName: string, script: string|null, rtl: bool} */
    public function toArray(): array {
        return ['code' => $this->code, 'englishName' => $this->englishName, 'nativeName' => $this->nativeName, 'script' => $this->script, 'rtl' => $this->rtl];
    }
}
