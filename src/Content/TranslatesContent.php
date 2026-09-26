<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use Illuminate\Database\Eloquent\Model;
use LonelyLights\Prosetta\Contracts\TranslatableContent;
use LogicException;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\ProsettaManager;

/**
 * Makes a model's text fields translatable content. Its keys follow the
 * record through saves, slug changes and deletes, once the save commits.
 *
 * @mixin Model
 */
trait TranslatesContent {
    /** The record key before this save, so a changed slug carries its translations over. */
    private ?string $prosettaPreviousRecord = null;

    /** @return array<string, string> field => a note for the AI on what the field holds */
    abstract public function translatableFields(): array;

    public static function bootTranslatesContent(): void {
        # The Interface Is How Prosetta (and Your IDE) Knows This Model's Shape; Say So Plainly When It's Missing
        if (! is_subclass_of(static::class, TranslatableContent::class)) {
            throw new LogicException(static::class.' uses TranslatesContent, so it must also implements TranslatableContent ('.TranslatableContent::class.').');
        }

        static::saving(function (self $model): void {
            $model->prosettaPreviousRecord = $model->exists ? $model->prosettaOriginal()->translationKey() : null;
        });

        static::saved(function (self $model): void {
            $previous = $model->prosettaPreviousRecord;
            $model->getConnection()->afterCommit(fn () => app(ContentKeys::class)->sync($model, $previous));
        });

        static::deleted(function (self $model): void {
            $folder = $model->translationFolder();
            $record = $model->translationKey();
            $model->getConnection()->afterCommit(fn () => app(ContentKeys::class)->obsolete($folder, $record));
        });
    }

    /** The folder under content/ that holds this model's keys. */
    public function translationFolder(): string {
        return $this->getTable();
    }

    /** The record part of each key: the route key (a slug where there is one). */
    public function translationKey(): string {
        return (string) $this->getRouteKey();
    }

    public function translationMaxLength(string $field): ?int {
        return null;
    }

    /** False keeps this record's keys obsolete, e.g. for a draft. */
    public function shouldTranslate(): bool {
        return true;
    }

    /** The approved translation of a field in a locale (default: the current one), else its English. */
    public function translated(string $field, ?string $locale = null): ?string {
        $english = $this->getAttribute($field);
        $locale ??= app()->getLocale();

        if (! array_key_exists($field, $this->translatableFields()) || $locale === app(LocaleSource::class)->source()) {
            return $english === null ? null : (string) $english;
        }

        return app(ContentTranslations::class)->value($this->translationFolder(), $this->translationKey(), $field, $locale)
            ?? ($english === null ? null : (string) $english);
    }

    /** @return array<string, string|null> every translatable field, translated */
    public function translations(?string $locale = null): array {
        $values = [];

        foreach (array_keys($this->translatableFields()) as $field) {
            $values[$field] = $this->translated($field, $locale);
        }

        return $values;
    }

    /** Asks for AI drafts of this record's fields now, rather than at the next cycle. */
    public function queueContent(): void {
        $refs = array_map(
            fn (string $field) => ContentKeys::NAMESPACE.'::'.$this->translationFolder().'.'.$this->translationKey().'.'.$field,
            array_keys($this->translatableFields()),
        );

        app(ProsettaManager::class)->translate(keys: $refs, queue: true);
    }

    /** This record as it was loaded, before unsaved changes. */
    private function prosettaOriginal(): static {
        $original = clone $this;
        $original->setRawAttributes($this->getRawOriginal());

        return $original;
    }
}
