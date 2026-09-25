<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use Illuminate\Database\Eloquent\Model;

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

    /** This record as it was loaded, before unsaved changes. */
    private function prosettaOriginal(): static {
        $original = clone $this;
        $original->setRawAttributes($this->getRawOriginal());

        return $original;
    }
}
