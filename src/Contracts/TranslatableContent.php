<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

/**
 * A model whose text fields Prosetta translates. Declare it beside the
 * TranslatesContent trait, which implements everything but
 * translatableFields():
 *
 *     final class Pillar extends Model implements TranslatableContent {
 *         use TranslatesContent;
 *     }
 */
interface TranslatableContent {
    /** @return array<string, string> field => a note for the AI on what the field holds */
    public function translatableFields(): array;

    /** The folder under content/ that holds this model's keys. */
    public function translationFolder(): string;

    /** The record part of each key. */
    public function translationKey(): string;

    public function translationMaxLength(string $field): ?int;

    /** False keeps this record's keys obsolete, e.g. for a draft. */
    public function shouldTranslate(): bool;

    /** The approved translation of a field in a locale (default: the current one), else its English. */
    public function translated(string $field, ?string $locale = null): ?string;

    /** @return array<string, string|null> every translatable field, translated */
    public function translations(?string $locale = null): array;
}
