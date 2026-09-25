<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Events\KeyAdded;
use LonelyLights\Prosetta\Events\KeyChanged;
use LonelyLights\Prosetta\Events\KeyObsoleted;
use LonelyLights\Prosetta\Guard\Placeholders;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Keeps translatable model fields as content keys: one folder per model
 * under the "content" namespace, one key per record and field.
 */
final readonly class ContentKeys {
    public const string NAMESPACE = 'content';

    public function __construct(private Dispatcher $events, private ContentTranslations $translations) {}

    public function file(string $folder): TranslationFile {
        $model = Settings::model('file');

        /** @var TranslationFile */
        return $model::query()->firstOrCreate(
            ['namespace' => self::NAMESPACE, 'group' => $folder],
            ['format' => FileFormat::Database],
        );
    }

    /**
     * Brings a record's keys in line with its fields: renames them when its
     * record key changed, then adds, updates, restores or obsoletes each one.
     *
     * @param Model $model a model using TranslatesContent
     */
    public function sync(Model $model, ?string $previousRecord = null): void {
        $file = $this->file($model->translationFolder());
        $record = $model->translationKey();

        if ($previousRecord !== null && $previousRecord !== $record) {
            $this->rename($file, $previousRecord, $record);
        }

        $existing = $this->keys($file, $record);

        if (! $model->shouldTranslate()) {
            $existing->each(fn (TranslationKey $key) => $this->retire($key, $file));

            return;
        }

        $keyModel = Settings::model('key');

        foreach ($model->translatableFields() as $field => $context) {
            $name = "$record.$field";
            $value = (string) ($model->getAttribute($field) ?? '');
            /** @var TranslationKey|null $current */
            $current = $existing->get($name);

            if ($value === '') {
                if ($current !== null) {
                    $this->retire($current, $file);
                }

                continue;
            }

            $attributes = [
                'source_value' => $value, 'source_hash' => Fingerprint::of($value),
                'placeholders' => Placeholders::unique($value), 'context' => $context,
                'max_length' => $model->translationMaxLength($field), 'obsolete_at' => null,
            ];

            if ($current === null) {
                /** @var TranslationKey $created */
                $created = $keyModel::query()->create(['file_id' => $file->getKey(), 'kind' => KeyKind::Content, 'key' => $name, ...$attributes]);
                $created->setRelation('file', $file);
                $this->events->dispatch(new KeyAdded($created));

                continue;
            }

            $previous = $current->source_value;
            $changed = $current->source_hash !== $attributes['source_hash'];
            $current->update($attributes);
            $current->setRelation('file', $file);

            if ($changed) {
                $this->events->dispatch(new KeyChanged($current, $previous));
            }
        }
    }

    /** Obsoletes every key of a deleted record. */
    public function obsolete(string $folder, string $record): void {
        $file = $this->file($folder);
        $this->keys($file, $record)->each(fn (TranslationKey $key) => $this->retire($key, $file));
    }

    /** @return Collection<string, TranslationKey> key => model, for one record */
    private function keys(TranslationFile $file, string $record): Collection {
        $keyModel = Settings::model('key');

        return $keyModel::query()->where('file_id', $file->getKey())->get()
            ->filter(fn (TranslationKey $key) => str_starts_with($key->key, "$record."))
            ->keyBy('key');
    }

    private function rename(TranslationFile $file, string $from, string $to): void {
        foreach ($this->keys($file, $from) as $key) {
            $key->update(['key' => $to.substr($key->key, strlen($from))]);
        }

        $this->translations->forget($file->group);
    }

    private function retire(TranslationKey $key, TranslationFile $file): void {
        if ($key->obsolete_at !== null) {
            return;
        }

        $key->update(['obsolete_at' => now()]);
        $this->translations->forget($file->group);
        $key->setRelation('file', $file);
        $this->events->dispatch(new KeyObsoleted($key));
    }
}
