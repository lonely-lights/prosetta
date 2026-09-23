<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Locales;

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Support\LocaleCode;
use LonelyLights\Prosetta\Support\Settings;

/** For apps without the locales table: prosetta.locales.fallback lists the codes; names are the codes. */
final readonly class ConfigLocaleSource implements LocaleSource {
    private const array RTL = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

    public function source(): string {
        return Settings::sourceLocale();
    }

    public function targets(): array {
        return array_values(array_map(
            fn (string $code) => $this->describe($code),
            array_filter($this->codes(), fn (string $code) => $code !== $this->source()),
        ));
    }

    public function find(string $code): ?LocaleDescriptor {
        return in_array($code, $this->codes(), true) || $code === $this->source() ? $this->describe($code) : null;
    }

    /** @return list<string> */
    public function autoTranslateTargets(): array {
        return [];
    }

    /** @return list<string> */
    private function codes(): array {
        return array_values(array_filter((array) config('prosetta.locales.fallback', []), 'is_string'));
    }

    private function describe(string $code): LocaleDescriptor {
        return new LocaleDescriptor($code, $code, $code, null, in_array(LocaleCode::language($code), self::RTL, true));
    }
}
