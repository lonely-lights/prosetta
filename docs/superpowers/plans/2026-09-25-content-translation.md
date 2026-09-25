# Content Translation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Any Eloquent model can declare translatable fields. Prosetta keeps them as content keys in `content/<folder>`, drafts and reviews them like lang strings, and serves approved translations from the database.

**Architecture:** Content reuses the existing tables:
- a `prosetta_files` row with namespace `content`, group = the model's folder and the new format `database`;
- `prosetta_keys` rows with kind `content` and key `<record>.<field>`;
- the usual `prosetta_translations`.

A model trait (`TranslatesContent`) syncs keys after commit. A small cached reader (`ContentTranslations`) serves approved values. Sync and export never touch content files. The editable guard exempts content keys, so they can be approved in production.

**Tech Stack:** PHP 8.3+, Laravel 11–13, Pest 3+ on Orchestra Testbench, Postgres or SQLite.

**Spec:** `docs/superpowers/specs/2026-09-25-content-translation-and-pillars-design.md` (Part 1).

## Global Constraints

- No new Composer dependencies; spatie/laravel-translatable is not used.
- Content keys live under namespace `content`; file group = `translationFolder()` (default: table name); key = `<translationKey()>.<field>` (default record key: the route key).
- Plain attribute access always returns the English column; translations are read only through `translated()` / `translations()`.
- Content files have `format = database` and are never read from or written to disk (no sync import, no export).
- Content keys are approvable when `prosetta.review.editable` is false; file keys keep today's behavior exactly.
- Key syncing runs after the host's transaction commits (`Connection::afterCommit`).
- Code style: `declare(strict_types=1)`, `final readonly` for stateless services, tables and models through `Settings::table()` / `Settings::model()`, Pest tests under `tests/Feature/Content/`.

## Review Focus

1. **A full `prosetta:sync` after content exists**: it must not mark content keys obsolete, even though no lang folder named `content` exists. Pinned in Task 1.
2. **A record whose slug changes while it has approved translations**: the translations stay attached under the new key, and the old key is not left behind as obsolete. Pinned in Task 2.
3. **A save inside a transaction that rolls back**: no content keys are created. Pinned in Task 2.
4. **A batch approve in production mixing content and file items**: content items are approved and file items are skipped as `locked`; the call never throws half-way. Pinned in Task 4.
5. **A translation approved while its cached folder is warm**: the next `translated()` returns the new value, not the cached one. Pinned in Task 3.

---

### Task 1: Content folders that sync and export leave alone

**Files:**
- Modify: `src/Enums/FileFormat.php`
- Modify: `src/Enums/KeyKind.php` (docblock only)
- Modify: `src/Sync/Syncer.php` (the two file queries that obsolete keys)
- Create: `src/Content/ContentKeys.php` (only `NAMESPACE` and `file()` in this task)
- Test: `tests/Feature/Content/ContentFolderTest.php`

**Interfaces:**
- Produces:
  - `FileFormat::Database` (value `'database'`);
  - `ContentKeys::NAMESPACE = 'content'`;
  - `ContentKeys::file(string $folder): TranslationFile`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use LonelyLights\Prosetta\Content\ContentKeys;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->directory = $this->useFixtureApp();
    $this->seedLocales();
});

it('keeps content keys current through a full sync, though no lang folder holds them', function () {
    $file = app(ContentKeys::class)->file('pillars');
    $key = TranslationKey::query()->create([
        'file_id' => $file->id, 'kind' => KeyKind::Content, 'key' => 'technology.name',
        'source_value' => 'Technology', 'source_hash' => sha1('Technology'),
    ]);

    app(Syncer::class)->sync();

    expect($file->refresh()->format)->toBe(FileFormat::Database)
        ->and($file->namespace)->toBe('content')
        ->and($key->refresh()->obsolete_at)->toBeNull();
});

it('reuses one folder per group', function () {
    $first = app(ContentKeys::class)->file('pillars');
    $again = app(ContentKeys::class)->file('pillars');

    expect($again->id)->toBe($first->id);
});

it('writes no file for content on export', function () {
    $file = app(ContentKeys::class)->file('pillars');
    TranslationKey::query()->create([
        'file_id' => $file->id, 'kind' => KeyKind::Content, 'key' => 'technology.name',
        'source_value' => 'Technology', 'source_hash' => sha1('Technology'),
    ]);

    app(Exporter::class)->export();

    expect(glob($this->directory.'/lang/*/pillars.php'))->toBe([])
        ->and(is_dir($this->directory.'/lang/content'))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Content/ContentFolderTest.php`
Expected: FAIL. `LonelyLights\Prosetta\Content\ContentKeys` is not found.

- [ ] **Step 3: Implement**

`src/Enums/FileFormat.php`:

```php
enum FileFormat: string {
    case Php = 'php';
    case Json = 'json';
    /** Content keys: kept in the database only, never read from or written to a lang file. */
    case Database = 'database';
}
```

`src/Enums/KeyKind.php` docblock becomes: `/** "content" keys hold Eloquent fields (TranslatesContent); they live in content/<folder> files. */`

`src/Content/ContentKeys.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Keeps translatable model fields as content keys: one folder per model
 * under the "content" namespace, one key per record and field.
 */
final readonly class ContentKeys {
    public const string NAMESPACE = 'content';

    public function file(string $folder): TranslationFile {
        $model = Settings::model('file');

        /** @var TranslationFile */
        return $model::query()->firstOrCreate(
            ['namespace' => self::NAMESPACE, 'group' => $folder],
            ['format' => FileFormat::Database],
        );
    }
}
```

`src/Sync/Syncer.php`: both queries that pick files whose keys get obsoleted skip content. In `syncRoot()`:

```php
$fileModel::query()->where('namespace', $root->namespace)->where('format', '!=', FileFormat::Database->value)->whereKeyNot($seen)->get()
```

In `obsoleteUndiscoveredNamespaces()`:

```php
$fileModel::query()->whereNotIn('namespace', $discovered)->where('format', '!=', FileFormat::Database->value)->get()
```

Add `use LonelyLights\Prosetta\Enums\FileFormat;` to the Syncer's imports.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Content/ContentFolderTest.php`
Expected: 3 passed. Then run the whole suite with `vendor/bin/pest`. Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Enums/FileFormat.php src/Enums/KeyKind.php src/Sync/Syncer.php src/Content/ContentKeys.php tests/Feature/Content/ContentFolderTest.php
git commit -m "Content folders: a database file format that sync and export leave alone"
```

---

### Task 2: The TranslatesContent trait keeps keys in step with records

**Files:**
- Create: `src/Content/TranslatesContent.php`
- Modify: `src/Content/ContentKeys.php` (add `sync()`, `obsolete()`, private helpers)
- Create: `tests/Fixtures/Models/Pillar.php`
- Test: `tests/Feature/Content/TranslatesContentTest.php`

**Interfaces:**
- Consumes: `ContentKeys::file()`, `FileFormat::Database` (Task 1).
- Produces:
  - trait `TranslatesContent` with `abstract translatableFields(): array<string,string>`, `translationFolder(): string`, `translationKey(): string`, `translationMaxLength(string $field): ?int` and `shouldTranslate(): bool`;
  - `ContentKeys::sync(Model $model, ?string $previousRecord = null): void`;
  - `ContentKeys::obsolete(string $folder, string $record): void`.
- `translated()` and `translations()` arrive in Task 3. `ContentKeys` gains a `ContentTranslations` dependency in Task 3; this task's constructor takes only the event dispatcher.

- [ ] **Step 1: Write the fixture model**

`tests/Fixtures/Models/Pillar.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use LonelyLights\Prosetta\Content\TranslatesContent;

/**
 * @property string $slug
 * @property string $name
 * @property string|null $badge
 * @property bool $draft
 */
final class Pillar extends Model {
    use TranslatesContent;

    protected $table = 'pillars';
    protected $guarded = [];
    protected $casts = ['draft' => 'boolean'];

    public function getRouteKeyName(): string {
        return 'slug';
    }

    public function translatableFields(): array {
        return [
            'name' => 'The full name of a pillar.',
            'badge' => 'A one- or two-word short name.',
        ];
    }

    public function translationMaxLength(string $field): ?int {
        return $field === 'badge' ? 20 : null;
    }

    public function shouldTranslate(): bool {
        return ! $this->draft;
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Content/TranslatesContentTest.php`:

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Tests\Fixtures\Models\Pillar;

beforeEach(function () {
    $this->seedLocales();
    Schema::create('pillars', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->unique();
        $table->string('name');
        $table->string('badge')->nullable();
        $table->boolean('draft')->default(false);
        $table->timestamps();
    });
});

function contentKey(string $key): ?TranslationKey {
    return TranslationKey::query()->where('key', $key)->whereHas('file', fn ($q) => $q->where('namespace', 'content')->where('group', 'pillars'))->first();
}

it('creates a content key per translatable field, with its context and length', function () {
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);

    $name = contentKey('technology.name');
    $badge = contentKey('technology.badge');

    expect($name->kind)->toBe(KeyKind::Content)
        ->and($name->source_value)->toBe('Technology')
        ->and($name->context)->toBe('The full name of a pillar.')
        ->and($badge->max_length)->toBe(20)
        ->and($name->ref()->toString())->toBe('content::pillars.technology.name');
});

it('updates the English and leaves approved translations to go stale', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    $key = contentKey('technology.name');
    Translation::query()->create([
        'key_id' => $key->id, 'locale' => 'es', 'value' => 'Tecnología', 'source_hash' => $key->source_hash,
        'approved_value' => 'Tecnología', 'approved_source_hash' => $key->source_hash, 'status' => TranslationStatus::Approved,
    ]);

    $pillar->update(['name' => 'Technology and Tools']);

    expect($key->refresh()->source_value)->toBe('Technology and Tools')
        ->and($key->source_hash)->not->toBe(Translation::query()->where('key_id', $key->id)->value('approved_source_hash'));
});

it('renames keys with the slug, keeping their translations', function () {
    $pillar = Pillar::query()->create(['slug' => 'tech', 'name' => 'Technology']);
    $id = contentKey('tech.name')->id;

    $pillar->update(['slug' => 'technology']);

    expect(contentKey('technology.name')->id)->toBe($id)
        ->and(contentKey('tech.name'))->toBeNull();
});

it('marks a deleted record\'s keys obsolete', function () {
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology'])->delete();

    expect(contentKey('technology.name')->obsolete_at)->not->toBeNull();
});

it('keeps a record that should not translate out of the queue until it should', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'draft' => true]);
    expect(contentKey('technology.name'))->toBeNull();

    $pillar->update(['draft' => false]);
    expect(contentKey('technology.name')->obsolete_at)->toBeNull();

    $pillar->update(['draft' => true]);
    expect(contentKey('technology.name')->obsolete_at)->not->toBeNull();
});

it('drops the key of a field that is emptied, and restores it when filled', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);

    $pillar->update(['badge' => null]);
    expect(contentKey('technology.badge')->obsolete_at)->not->toBeNull();

    $pillar->update(['badge' => 'Tech']);
    expect(contentKey('technology.badge')->obsolete_at)->toBeNull();
});

it('creates no keys when the saving transaction rolls back', function () {
    try {
        DB::transaction(function (): void {
            Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(contentKey('technology.name'))->toBeNull();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Content/TranslatesContentTest.php`
Expected: FAIL. Trait `LonelyLights\Prosetta\Content\TranslatesContent` is not found.

- [ ] **Step 4: Implement the trait**

`src/Content/TranslatesContent.php`:

```php
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
```

- [ ] **Step 5: Implement ContentKeys::sync() and obsolete()**

Replace `src/Content/ContentKeys.php` with:

```php
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

    public function __construct(private Dispatcher $events) {}

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
    }

    private function retire(TranslationKey $key, TranslationFile $file): void {
        if ($key->obsolete_at !== null) {
            return;
        }

        $key->update(['obsolete_at' => now()]);
        $key->setRelation('file', $file);
        $this->events->dispatch(new KeyObsoleted($key));
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Content`
Expected: all pass. If `afterCommit` callbacks never fire under `RefreshDatabase`, check the test base. Testbench runs after-commit callbacks when the outermost non-test transaction ends (Laravel ≥ 10). Do not work around it with `DB::commit()`.

- [ ] **Step 7: Commit**

```bash
git add src/Content tests/Fixtures/Models/Pillar.php tests/Feature/Content/TranslatesContentTest.php
git commit -m "TranslatesContent: a model's fields become content keys that follow the record"
```

---

### Task 3: Reading translations, cached, fresh after approval

**Files:**
- Create: `src/Content/ContentTranslations.php`
- Create: `src/Content/ForgetApprovedContent.php` (the listener)
- Modify: `src/Content/TranslatesContent.php` (add `translated()`, `translations()` and `queueContent()`)
- Modify: `src/Content/ContentKeys.php` (inject `ContentTranslations`; forget the folder after `rename()` and `retire()`)
- Modify: `src/ProsettaServiceProvider.php` (register the listener)
- Test: `tests/Feature/Content/ContentTranslationsTest.php`

**Interfaces:**
- Consumes: the trait and `ContentKeys` (Task 2).
- Produces:
  - `ContentTranslations::value(string $folder, string $record, string $field, string $locale): ?string`;
  - `ContentTranslations::forget(string $folder): void`;
  - `TranslatesContent::translated(string $field, ?string $locale = null): ?string`;
  - `TranslatesContent::translations(?string $locale = null): array<string, ?string>`;
  - `TranslatesContent::queueContent(): void`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Tests\Fixtures\Models\Pillar;

beforeEach(function () {
    $this->seedLocales();
    Schema::create('pillars', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->unique();
        $table->string('name');
        $table->string('badge')->nullable();
        $table->boolean('draft')->default(false);
        $table->timestamps();
    });
    $this->pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);
});

it('reads the approved translation for the current locale, and English otherwise', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);

    app()->setLocale('es');
    expect($this->pillar->translated('name'))->toBe('Tecnología')
        ->and($this->pillar->translated('badge'))->toBe('Tech')
        ->and($this->pillar->name)->toBe('Technology');

    app()->setLocale('en');
    expect($this->pillar->translated('name'))->toBe('Technology')
        ->and($this->pillar->translated('name', 'es'))->toBe('Tecnología');
});

it('never serves a draft', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Borrador', null);

    expect($this->pillar->translated('name', 'es'))->toBe('Technology');
});

it('serves a new approval even when the folder was already cached', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);
    expect($this->pillar->translated('name', 'es'))->toBe('Tecnología');

    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnologías', null, approve: true);

    expect($this->pillar->translated('name', 'es'))->toBe('Tecnologías');
});

it('gives every translatable field at once', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);

    expect($this->pillar->translations('es'))->toBe(['name' => 'Tecnología', 'badge' => 'Tech']);
});

it('keeps serving the translation after the slug changes', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);
    expect($this->pillar->translated('name', 'es'))->toBe('Tecnología');

    $this->pillar->update(['slug' => 'tech']);

    expect($this->pillar->translated('name', 'es'))->toBe('Tecnología');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Content/ContentTranslationsTest.php`
Expected: FAIL. `Call to undefined method ...Pillar::translated()`.

- [ ] **Step 3: Implement the reader**

`src/Content/ContentTranslations.php`:

```php
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
```

`src/Content/ForgetApprovedContent.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Content;

use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Events\TranslationApproved;

/** A content approval must show at once, so its folder's cache goes. */
final readonly class ForgetApprovedContent {
    public function __construct(private ContentTranslations $translations) {}

    public function handle(TranslationApproved $event): void {
        $file = $event->translation->key->file;

        if ($file->format === FileFormat::Database) {
            $this->translations->forget($file->group);
        }
    }
}
```

In `ProsettaServiceProvider::boot()` (beside the Gate definitions): `Event::listen(TranslationApproved::class, ForgetApprovedContent::class);`. Add the `Event` facade import if it isn't there yet.

In `ContentKeys`, the constructor becomes `__construct(private Dispatcher $events, private ContentTranslations $translations)`. Then:
- `rename()` ends with `$this->translations->forget($file->group);`;
- `retire()` calls it after updating.

- [ ] **Step 4: Add the reading methods to the trait**

```php
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
```

Imports for the trait: `LonelyLights\Prosetta\Contracts\LocaleSource` and `LonelyLights\Prosetta\ProsettaManager`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Content`
Expected: all pass. Then run the whole suite with `vendor/bin/pest`.

- [ ] **Step 6: Commit**

```bash
git add src/Content src/ProsettaServiceProvider.php tests/Feature/Content/ContentTranslationsTest.php
git commit -m "Read approved content translations from a per-folder cache that clears on approval"
```

---

### Task 4: Content stays approvable where review is read-only

**Files:**
- Modify: `src/Review/Viewer.php` (`editable()` takes an optional key)
- Modify: `src/Review/ReviewService.php` (check per translation; `approveClean()` narrows to content when locked)
- Modify: `src/Review/ReviewDesk.php` (skip locked items as `locked` in batches; keep the desk-level guard for `redraft` and `runCycle`)
- Modify: `src/Review/QueueItem.php`, `src/Review/KeyRow.php` (an `editable` flag)
- Test: `tests/Feature/Content/ContentEditableTest.php`

**Interfaces:**
- Consumes: `FileFormat::Database` (Task 1); the `Pillar` fixture (Task 2).
- Produces:
  - `Viewer::editable(?TranslationKey $key = null): bool`, which is true for any content key;
  - `QueueItem::$editable` and `KeyRow::$editable` (bool, last constructor parameter);
  - batch skip reason `'locked'`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Review\ReviewDesk;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Tests\Fixtures\Models\Pillar;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    Schema::create('pillars', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->unique();
        $table->string('name');
        $table->string('badge')->nullable();
        $table->boolean('draft')->default(false);
        $table->timestamps();
    });
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    $this->content = app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null);
    $this->file = Translation::query()->where('locale', 'es')->whereHas('key', fn ($q) => $q->where('kind', 'file'))->first();
    $this->file->update(['status' => TranslationStatus::Draft, 'value' => 'Borrador']);
    $this->user = new GenericUser(['id' => 'u1']);
    config(['prosetta.review.editable' => false]);
});

it('lets a person approve and edit content where file translations are locked', function () {
    app(ReviewService::class)->approve([$this->content->id], $this->user);
    expect($this->content->refresh()->status)->toBe(TranslationStatus::Approved);

    app(ReviewService::class)->edit($this->content->id, 'Tecnologías', $this->user);
    expect($this->content->refresh()->value)->toBe('Tecnologías');

    expect(fn () => app(ReviewService::class)->approve([$this->file->id], $this->user))->toThrow(ReviewLocked::class);
});

it('approves the content in a mixed batch and skips the locked file item', function () {
    $viewer = Viewer::for($this->user);
    $report = app(ReviewDesk::class)->approveMany($viewer, [
        $this->content->id => ReviewService::fingerprint($this->content),
        $this->file->id => ReviewService::fingerprint($this->file),
    ]);

    expect($report->approved)->toBe(1)
        ->and($report->skipped[$this->file->id])->toBe('locked')
        ->and($this->file->refresh()->status)->toBe(TranslationStatus::Draft);
});

it('approves only content when approving clean items while locked', function () {
    $report = app(ReviewService::class)->approveClean('es', by: $this->user);

    expect($report->approved)->toBe([$this->content->id]);
});

it('marks each queue item with whether it can be changed here', function () {
    $items = collect(app(ReviewQueue::class)->all(Viewer::for($this->user), ['locale' => 'es']))->keyBy('translationId');

    expect($items[$this->content->id]->editable)->toBeTrue()
        ->and($items[$this->file->id]->editable)->toBeFalse();
});
```

Note: `Viewer::for()` depends on the Authorizer. Check `tests/Feature/Review/ReviewDeskTest.php` for how a test grants a user every locale (`Prosetta::authorizeUsing(...)` or the default allow-all), and copy that setup into `beforeEach`. Similarly, match `ReviewQueue::all()`'s real signature to the one in `src/Review/ReviewQueue.php`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Content/ContentEditableTest.php`
Expected: FAIL. The first test throws `ReviewLocked` on the content approval.

- [ ] **Step 3: Implement**

`Viewer::editable()`:

```php
    /** Whether people may change translations here; content keys can always be changed, since they never reach a file. */
    public static function editable(?TranslationKey $key = null): bool {
        if ($key !== null && $key->file->format === FileFormat::Database) {
            return true;
        }

        $configured = config('prosetta.review.editable');

        return $configured === null
            ? app()->environment('local', 'staging')
            : filter_var($configured, FILTER_VALIDATE_BOOL);
    }
```

`ReviewService`: `unlocked()` becomes `private function unlocked(?Authenticatable $by, ?TranslationKey $key = null): void`, calling `Viewer::editable($key)`. The call sites change as follows:
- `edit()`: move the check after `load()` and pass `$translation->key`;
- `write()`: move it after the key is found and pass `$key`;
- `reject()` and `confirm()`: after `load()`, pass `$translation->key`;
- `approve()`: drop the top-level call, and add `$this->unlocked($by, $translation->key);` inside the first loop (the one that authorizes every locale), so a mixed batch refuses before any write;
- `approveClean()`: drop the top-level call. When `$by !== null && ! Viewer::editable()`, add `->where("$f.format", FileFormat::Database->value)` to the id query.

`ReviewDesk`:
- `approveMatching()`, `approveMany()` and `rejectMany()` drop `$this->unlocked($viewer)`;
- `admissible()` loads the translations with `key.file` (not only their locales), and after the review check skips any where `$viewer->user !== null && ! Viewer::editable($translation->key)` with `$report->skipped[$id] = 'locked'`;
- in `approveMatching()`, add `|| ! $item->editable` to the first `continue` condition;
- `redraft()` and `runCycle()` keep the desk-level `unlocked($viewer)`. AI drafting stays a dev and staging action.

`QueueItem`: add `public bool $editable,` as the last constructor parameter, and pass `Viewer::editable($key)` last in `from()`. `KeyRow`: likewise, adding `Viewer::editable($key)` in `from()`. `toArray()` picks both up through `get_object_vars`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/Content/ContentEditableTest.php tests/Feature/Review`
Expected: all pass. `EditableGuardTest` still passes unchanged, because file keys behave as before. Then run the whole suite with `vendor/bin/pest`.

- [ ] **Step 5: Commit**

```bash
git add src/Review tests/Feature/Content/ContentEditableTest.php
git commit -m "Content stays approvable where review is read-only; rows say whether they can change"
```

---

### Task 5: Import existing translations into content keys

**Files:**
- Create: `src/Console/ContentImportCommand.php`
- Modify: `src/ProsettaServiceProvider.php` (register the command)
- Modify: `README.md` (a short "Translating database content" section)
- Test: `tests/Feature/Content/ContentImportTest.php`

**Interfaces:**
- Consumes: `ReviewService::write()`; content keys (Task 2).
- Produces: `prosetta:content:import {folder} {locale} {path}`. It prints `Imported N, unmatched M, flagged F.` and exits 0, or 1 when the file is missing or not a PHP array.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Tests\Fixtures\Models\Pillar;

beforeEach(function () {
    $this->seedLocales();
    Schema::create('pillars', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->unique();
        $table->string('name');
        $table->string('badge')->nullable();
        $table->boolean('draft')->default(false);
        $table->timestamps();
    });
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);
    $this->path = tempnam(sys_get_temp_dir(), 'pillars').'.php';
    file_put_contents($this->path, "<?php return ['technology' => ['name' => 'Tecnología', 'badge' => 'Tec'], 'retired' => ['name' => 'Viejo']];");
});

afterEach(fn () => @unlink($this->path));

it('imports a lang file as approved translations of matching content keys', function () {
    $this->artisan('prosetta:content:import', ['folder' => 'pillars', 'locale' => 'es', 'path' => $this->path])
        ->expectsOutput('Imported 2, unmatched 1, flagged 0.')
        ->assertExitCode(0);

    expect(Translation::query()->where('locale', 'es')->where('status', TranslationStatus::Approved)->count())->toBe(2)
        ->and(Pillar::query()->first()->translated('name', 'es'))->toBe('Tecnología');
});

it('fails on a missing file', function () {
    $this->artisan('prosetta:content:import', ['folder' => 'pillars', 'locale' => 'es', 'path' => '/nope.php'])
        ->assertExitCode(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Content/ContentImportTest.php`
Expected: FAIL. The command `prosetta:content:import` is not defined.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use LonelyLights\Prosetta\Content\ContentKeys;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Review\ReviewService;

/** Carries a lang file's translations into content keys, e.g. when a catalogue moves from lang files to a table. */
final class ContentImportCommand extends Command {
    protected $signature = 'prosetta:content:import {folder : The content folder, e.g. pillars} {locale} {path : A PHP lang file returning an array}';

    protected $description = 'Import a lang file as approved translations of matching content keys';

    public function handle(ContentKeys $content, ReviewService $review): int {
        $path = (string) $this->argument('path');
        $values = is_file($path) ? include $path : null;

        if (! is_array($values)) {
            $this->error("[$path] is not a PHP file returning an array.");

            return self::FAILURE;
        }

        $folder = (string) $this->argument('folder');
        $locale = (string) $this->argument('locale');
        $keys = $content->file($folder)->keys()->whereNull('obsolete_at')->get()->keyBy(fn (TranslationKey $key) => $key->key);
        [$imported, $unmatched, $flagged] = [0, 0, 0];

        foreach (Arr::dot($values) as $name => $value) {
            if (! $keys->has((string) $name) || ! is_string($value)) {
                $unmatched++;

                continue;
            }

            try {
                $review->write(ContentKeys::NAMESPACE."::$folder.$name", $locale, $value, null, 'Imported from '.basename($path).'.', approve: true);
                $imported++;
            } catch (ProsettaException) {
                $review->write(ContentKeys::NAMESPACE."::$folder.$name", $locale, $value, null, 'Imported from '.basename($path).'; needs review.');
                $flagged++;
            }
        }

        $this->line("Imported $imported, unmatched $unmatched, flagged $flagged.");

        return self::SUCCESS;
    }
}
```

Register it in the provider's `commands([...])` list.

README section, under the usage docs:

```markdown
## Translating database content

Add `TranslatesContent` to a model and list its fields, each with a note for the AI:

    use LonelyLights\Prosetta\Content\TranslatesContent;

    public function translatableFields(): array {
        return ['name' => 'The name of a pillar.', 'subtitle' => 'One sentence describing it.'];
    }

Saving the model keeps its keys under `content/<table>` (`content::pillars.technology.name`); the cycle drafts them and they are reviewed like any string. Read them with `$model->translated('name')` or `$model->translations()`; `$model->name` stays English. Content can be approved in production, and is never exported to files. `prosetta:content:import pillars es lang/es/pillars.php` carries existing lang-file translations over.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Content`, then `vendor/bin/pest`.
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Console/ContentImportCommand.php src/ProsettaServiceProvider.php README.md tests/Feature/Content/ContentImportTest.php
git commit -m "prosetta:content:import carries lang-file translations into content keys"
```
