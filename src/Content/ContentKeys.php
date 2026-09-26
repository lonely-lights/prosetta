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
use LonelyLights\Prosetta\Exceptions\ProsettaException;
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
            $restored = $current->obsolete_at !== null;
            $current->update($attributes);
            $current->setRelation('file', $file);

            if ($restored) {
                $this->translations->forget($file->group);
            }

            if ($changed) {
                $this->events->dispatch(new KeyChanged($current, $previous));
            }
        }

        # A Field the Model No Longer Translates Stops Waiting for Drafts and Review
        foreach ($existing as $name => $key) {
            if (! array_key_exists(substr($name, strlen($record) + 1), $model->translatableFields())) {
                $this->retire($key, $file);
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

        # Narrowed in SQL (a folder can hold thousands of records' keys), Then Checked Exactly Here, Since LIKE's Case Rules Vary by Database
        $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $record).'.%';

        $query = $keyModel::query();
        $column = $query->getQuery()->getGrammar()->wrap('key');

        return $query->where('file_id', $file->getKey())->whereRaw("$column like ? escape '!'", [$prefix])->get()
            ->filter(fn (TranslationKey $key) => str_starts_with($key->key, "$record."))
            ->keyBy('key');
    }

    /** @throws ProsettaException when another current record already holds the new record key */
    private function rename(TranslationFile $file, string $from, string $to): void {
        $taken = $this->keys($file, $to);

        foreach ($this->keys($file, $from) as $key) {
            $name = $to.substr($key->key, strlen($from));
            $leftover = $taken->get($name);

            if ($leftover !== null && $leftover->obsolete_at === null) {
                throw new ProsettaException("Content key [$name] already belongs to a current record.");
            }

            # A Deleted Record's Keys (and Their Translations) Give Way to the Record Taking Its Name
            $leftover?->delete();
            $key->update(['key' => $name]);
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
