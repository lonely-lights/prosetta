<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Locales;

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Support\Settings;

/** Reads the locales table on every call: few rows, and always fresh in long-lived queue workers. */
final class DatabaseLocaleSource implements LocaleSource {
    public function source(): string {
        return Settings::sourceLocale();
    }

    public function targets(): array {
        $model = Settings::model('locale');
        $source = $this->source();

        return $model::query()->targets()->ordered()->get()
            ->reject(fn (Locale $locale) => $locale->locale_initials === $source)
            ->map(fn (Locale $locale) => $locale->toDescriptor())
            ->values()
            ->all();
    }

    public function find(string $code): ?LocaleDescriptor {
        $model = Settings::model('locale');

        return $model::query()->where('locale_initials', $code)->first()?->toDescriptor();
    }
}
