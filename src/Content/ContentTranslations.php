<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use Illuminate\Contracts\Cache\Repository;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Support\Settings;

/** Approved content translations, cached per folder and locale. */
final readonly class ContentTranslations {
    public function __construct(private Repository $cache, private LocaleSource $locales) {}

    public function value(string $folder, string $record, string $field, string $locale): ?string {
        return $this->folder($folder, $locale)["$record.$field"] ?? null;
    }

    /** Drops every locale's cache of a folder, e.g. after an approval or a rename. */
    public function forget(string $folder): void {
        foreach ($this->locales->targets() as $locale) {
            $this->cache->forget($this->cacheKey($folder, $locale->code));
        }
    }

    /** @return array<string, string> key => approved value */
    private function folder(string $folder, string $locale): array {
        return $this->cache->rememberForever($this->cacheKey($folder, $locale), function () use ($folder, $locale): array {
            $t = Settings::table('translations');
            $k = Settings::table('keys');
            $f = Settings::table('files');
            $model = Settings::model('translation');

            return $model::query()->toBase()
                ->join($k, "$k.id", '=', "$t.key_id")
                ->join($f, "$f.id", '=', "$k.file_id")
                ->where("$f.namespace", ContentKeys::NAMESPACE)
                ->where("$f.group", $folder)
                ->where("$t.locale", $locale)
                ->whereNull("$k.obsolete_at")
                ->whereNotNull("$t.approved_value")
                ->pluck("$t.approved_value", "$k.key")
                ->map(fn ($value) => (string) $value)
                ->all();
        });
    }

    private function cacheKey(string $folder, string $locale): string {
        return "prosetta.content.$folder.$locale";
    }
}
