<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Locales;

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Support\LocaleCode;
use LonelyLights\Prosetta\Support\Settings;

/** Reads the locales table on every call: few rows, and always fresh in long-lived queue workers. */
final readonly class DatabaseLocaleSource implements LocaleSource {
    public function source(): string {
        return Settings::sourceLocale();
    }

    public function targets(): array {
        $model = Settings::model('locale');
        $source = $this->source();

        return $model::query()->targets()->ordered()->get()
            ->reject(fn (Locale $locale) => $locale->locale_initials === $source)
            ->map(fn (Locale $locale) => $this->describe($locale))
            ->values()
            ->all();
    }

    public function find(string $code): ?LocaleDescriptor {
        $model = Settings::model('locale');
        $locale = $model::query()->where('locale_initials', $code)->first();

        return $locale === null ? null : $this->describe($locale);
    }

    /** @return list<string> */
    public function autoTranslateTargets(): array {
        $model = Settings::model('locale');

        return $model::query()->scopes(['targets', 'autoTranslate'])
            ->where('locale_initials', '!=', $this->source())
            ->pluck('locale_initials')->values()->all();
    }

    /** A regional locale with no note or glossary of its own inherits its base language's. */
    private function describe(Locale $locale): LocaleDescriptor {
        $descriptor = $locale->toDescriptor();
        $language = LocaleCode::language($descriptor->code);

        if ($language === $descriptor->code || ($descriptor->styleNote !== null && $descriptor->glossary !== [])) {
            return $descriptor;
        }

        $model = Settings::model('locale');
        $base = $model::query()->where('locale_initials', $language)->first();

        return $base === null ? $descriptor : new LocaleDescriptor(
            $descriptor->code, $descriptor->englishName, $descriptor->nativeName, $descriptor->script, $descriptor->rtl,
            $descriptor->styleNote ?? $base->style_note,
            $descriptor->glossary !== [] ? $descriptor->glossary : (array) ($base->glossary ?? []),
        );
    }
}
