# Prosetta Rebuild Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild Prosetta as a headless Laravel package that treats the source-language lang files as canonical keys. It drafts other locales through a host-supplied AI driver, routes them through per-language review, and exports approved text to root and module lang folders.

**Architecture:**
- Small single-purpose services under `LonelyLights\Prosetta`:
  - discovery (`RootDiscovery`, `LangReader`);
  - sync (`Syncer`, `Renamer`);
  - translation (`Translator`, `TranslationRunner`, a queued `TranslateBatch` job);
  - review (`ReviewService`, `ReviewQueue`);
  - export (`Exporter` and its writers);
  - queries (`Stats`, `KeyFinder`).
- These are composed by `ProsettaManager`, which sits behind a `Prosetta` facade and seven Artisan commands.
- State lives in five tables. Work lists are derived from source hashes and statuses, never stored.

**Tech Stack:** PHP ^8.3, Laravel components ^11|^12|^13, Pest ^5.1, orchestra/testbench ^11, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-09-22-prosetta-rebuild-design.md`. Read it before starting; §16a lists four amendments this plan relies on.

## Global Constraints

- Work only in the worktree `C:\Websites\packages\prosetta-rebuild` on branch `rebuild/v1`. Never touch `C:\Websites\packages\prosetta` (`main`, live in Undaunted through a junction). Never delete anything under any `vendor/` except this worktree's own `vendor/` via Composer.
- Package `lonely-lights/prosetta`, namespace `LonelyLights\Prosetta`, PSR-4 `src/`; tests are `LonelyLights\Prosetta\Tests` → `tests/`.
- `require`: `php ^8.3`; `illuminate/{auth,bus,console,contracts,database,filesystem,queue,support,translation} ^11.0|^12.0|^13.0`. `require-dev`: `orchestra/testbench ^11.0`, `pestphp/pest ^5.1`, `pestphp/pest-plugin-laravel ^5.0`. `suggest`: `spatie/laravel-translatable`.
- Every PHP file in `src/` starts with `declare(strict_types=1);`. Braces go on the same line as the declaration (Undaunted's house style).
- Headless: no views, routes or controllers.
- Prosetta **never writes source-locale files** and never deletes files.
- Reviewer and user ids are strings (`(string) $user->getAuthIdentifier()`), never foreign keys.
- Table names come from `Settings::table()`, which reads `prosetta.table_names.*`, then the legacy `prosetta.tableNames.*`, then `prosetta_{name}`. Model classes come from `Settings::model()`.
- `TranslationDriver` DTOs are readonly and contain no Eloquent models (keeps a hosted driver possible).
- Default `exclude_paths` is `['lang/vendor', 'vendor']`.
- Run tests with `vendor/bin/pest` from the worktree root.
- End every commit message with these two lines, as its final paragraph:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2
  ```

## Review Focus

These five inputs are implied by the spec but no feature test naturally hits them, and they're the ones most likely to bite a real user. Each has a pinned test in the owning task.

1. **A source lang file that doesn't parse or doesn't return an array.** Expected: sync fails loudly naming the file, and nothing is marked obsolete (Task 8, test "a broken source file aborts the sync without obsoleting anything").
2. **Non-string leaves (`'limit' => 25`, `null`, `true`) inside lang arrays.** Expected: silently skipped, never keys, never exported, no crash (Task 7, test "skips values that are not strings").
3. **Values with quotes, backslashes, newlines, emoji or right-to-left text.** Expected: they export to PHP and read back byte-identical (Task 13, test "round-trips awkward characters").
4. **List arrays (`'steps' => ['a', 'b']`) and their numeric keys.** Expected: they flatten to `steps.0`/`steps.1` and export back as a list (Task 13, test "exports list arrays back as lists").
5. **JSON keys that contain dots or `::`** (`"Version 2.0 is ready."`). Expected: they're treated as one whole key and never split into groups (Task 3, test "keeps dots and double colons inside JSON keys"; Task 7, test "keeps JSON keys whole").

---

### Task 1: Scaffold the blank-slate package

**Files:**
- Delete: `src/`, `tests/`, `resources/`, `routes/`, `database/`, `config/`, `docs/CODE_AUDIT.md`, `composer.lock`, `phpunit.xml`
- Create: `composer.json` (replace), `phpunit.xml`, `config/prosetta.php`, `src/ProsettaServiceProvider.php`, `src/Support/Settings.php`, `src/Support/Fingerprint.php`, `tests/TestCase.php`, `tests/Pest.php`, `tests/Feature/ServiceProviderTest.php`
- Modify: `.gitignore`

**Interfaces:**
- Produces: `Settings::sourceLocale(): string`, `Settings::table(string $name): string`, `Settings::model(string $name): string` (class-string); `Fingerprint::of(string $value): string` (sha256 hex, 64 chars); `LonelyLights\Prosetta\Tests\TestCase` (testbench, loads `ProsettaServiceProvider`).

- [ ] **Step 1: Remove the legacy code**

```bash
git rm -r -q src tests resources routes database config docs/CODE_AUDIT.md composer.lock phpunit.xml
```

Append to `.gitignore`:

```
/composer.lock
.phpunit.result.cache
```

- [ ] **Step 2: Write `composer.json`**

```json
{
    "name": "lonely-lights/prosetta",
    "description": "Translation workflow for Laravel: canonical source lang files, AI drafts through a host-supplied driver, per-language review, and export to root and module lang folders.",
    "type": "library",
    "license": "MIT",
    "keywords": ["laravel", "translation", "i18n", "localization", "ai", "prosetta"],
    "homepage": "https://github.com/lonely-lights/prosetta",
    "authors": [
        {
            "name": "Andrew K. Hartley",
            "homepage": "https://andrewkhartley.com",
            "email": "andrew@andrewkhartley.com",
            "role": "Creator"
        }
    ],
    "require": {
        "php": "^8.3",
        "illuminate/auth": "^11.0|^12.0|^13.0",
        "illuminate/bus": "^11.0|^12.0|^13.0",
        "illuminate/console": "^11.0|^12.0|^13.0",
        "illuminate/contracts": "^11.0|^12.0|^13.0",
        "illuminate/database": "^11.0|^12.0|^13.0",
        "illuminate/filesystem": "^11.0|^12.0|^13.0",
        "illuminate/queue": "^11.0|^12.0|^13.0",
        "illuminate/support": "^11.0|^12.0|^13.0",
        "illuminate/translation": "^11.0|^12.0|^13.0"
    },
    "require-dev": {
        "orchestra/testbench": "^11.0",
        "pestphp/pest": "^5.1",
        "pestphp/pest-plugin-laravel": "^5.0"
    },
    "suggest": {
        "spatie/laravel-translatable": "Planned: translate Eloquent model content through Prosetta's review workflow."
    },
    "autoload": {
        "psr-4": {
            "LonelyLights\\Prosetta\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "LonelyLights\\Prosetta\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "vendor/bin/pest"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "LonelyLights\\Prosetta\\ProsettaServiceProvider"
            ],
            "aliases": {
                "Prosetta": "LonelyLights\\Prosetta\\Facades\\Prosetta"
            }
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 3: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="testing"/>
    </php>
</phpunit>
```

- [ ] **Step 4: Write `config/prosetta.php`**

```php
<?php

return [

    /*
    | The locale whose lang files are canonical. Prosetta reads these files and
    | never writes them; every other locale follows them.
    */
    'source_locale' => env('PROSETTA_SOURCE_LOCALE', 'en'),

    /*
    | Lang roots. '*' is lang_path() (PHP groups plus {locale}.json). Other
    | namespaces are discovered from every loadTranslationsFrom() hint the
    | translator knows about; include/exclude filter them by name.
    */
    'namespaces' => [
        'discover' => true,
        'include' => ['*'],
        'exclude' => [],
    ],

    /*
    | Explicit namespace => path overrides, e.g.
    | 'identity' => app_path('Modules/Identity/Lang'). '*' overrides lang_path().
    */
    'paths' => [],

    /*
    | Paths Prosetta skips when reading and refuses when writing. Relative
    | entries resolve against base_path(); globs are allowed.
    */
    'exclude_paths' => ['lang/vendor', 'vendor'],

    'export' => [
        'include_drafts' => env('PROSETTA_EXPORT_DRAFTS', false),
    ],

    'review' => [
        'allow_self_approval' => true,
    ],

    'ai' => [
        'driver' => null,
        'model' => env('PROSETTA_AI_MODEL'),
        'models' => [],
        'batch' => 25,
        'retries_on_issues' => 1,
    ],

    'queue' => [
        'connection' => env('PROSETTA_QUEUE_CONNECTION'),
        'name' => env('PROSETTA_QUEUE', 'translations'),
        'rate_per_minute' => env('PROSETTA_AI_RATE', 60),
    ],

    'locales' => [
        'source' => \LonelyLights\Prosetta\Locales\DatabaseLocaleSource::class,
        'fallback' => ['en'],
    ],

    'models' => [
        'locale' => \LonelyLights\Prosetta\Models\Locale::class,
        'file' => \LonelyLights\Prosetta\Models\TranslationFile::class,
        'key' => \LonelyLights\Prosetta\Models\TranslationKey::class,
        'translation' => \LonelyLights\Prosetta\Models\Translation::class,
        'review' => \LonelyLights\Prosetta\Models\TranslationReview::class,
    ],

    'table_names' => [
        'locales' => 'prosetta_locales',
        'files' => 'prosetta_files',
        'keys' => 'prosetta_keys',
        'translations' => 'prosetta_translations',
        'reviews' => 'prosetta_reviews',
    ],

    'log_channel' => null,

];
```

- [ ] **Step 5: Write the test harness and the failing test**

`tests/TestCase.php`:

```php
<?php

namespace LonelyLights\Prosetta\Tests;

use LonelyLights\Prosetta\ProsettaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra {
    protected function getPackageProviders($app): array {
        return [ProsettaServiceProvider::class];
    }
}
```

`tests/Pest.php`:

```php
<?php

uses(LonelyLights\Prosetta\Tests\TestCase::class)->in('Feature', 'Unit');
```

`tests/Feature/ServiceProviderTest.php`:

```php
<?php

use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\Settings;

it('merges the package config', function () {
    expect(config('prosetta.source_locale'))->toBe('en')
        ->and(config('prosetta.exclude_paths'))->toBe(['lang/vendor', 'vendor']);
});

it('resolves table names, falling back to the legacy camelCase key', function () {
    expect(Settings::table('locales'))->toBe('prosetta_locales');

    config()->set('prosetta.table_names.locales', null);
    config()->set('prosetta.tableNames.locales', 'legacy_locales');

    expect(Settings::table('locales'))->toBe('legacy_locales');

    config()->set('prosetta.tableNames.locales', null);

    expect(Settings::table('locales'))->toBe('prosetta_locales');
});

it('resolves model classes from config', function () {
    config()->set('prosetta.models.locale', 'App\\Models\\Locale');

    expect(Settings::model('locale'))->toBe('App\\Models\\Locale')
        ->and(Settings::model('file'))->toBe('LonelyLights\\Prosetta\\Models\\TranslationFile');
});

it('fingerprints values with sha256', function () {
    expect(Fingerprint::of('abc'))->toBe(hash('sha256', 'abc'))->toHaveLength(64);
});
```

- [ ] **Step 6: Install and run the test to verify it fails**

Run: `composer install && vendor/bin/pest tests/Feature/ServiceProviderTest.php`
Expected: FAIL. The test can't boot because `LonelyLights\Prosetta\ProsettaServiceProvider` doesn't exist.

- [ ] **Step 7: Implement the provider and support classes**

`src/Support/Settings.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** Reads Prosetta's config with its defaults, so no other class repeats them. */
final class Settings {
    private const MODELS = [
        'locale' => \LonelyLights\Prosetta\Models\Locale::class,
        'file' => \LonelyLights\Prosetta\Models\TranslationFile::class,
        'key' => \LonelyLights\Prosetta\Models\TranslationKey::class,
        'translation' => \LonelyLights\Prosetta\Models\Translation::class,
        'review' => \LonelyLights\Prosetta\Models\TranslationReview::class,
    ];

    public static function sourceLocale(): string {
        return (string) config('prosetta.source_locale', 'en');
    }

    public static function table(string $name): string {
        return (string) (config("prosetta.table_names.$name") ?? config("prosetta.tableNames.$name") ?? "prosetta_$name");
    }

    /** @return class-string */
    public static function model(string $name): string {
        return (string) (config("prosetta.models.$name") ?? self::MODELS[$name]);
    }
}
```

`src/Support/Fingerprint.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** The one hash Prosetta uses for source values, file values and key lookups. */
final class Fingerprint {
    public static function of(string $value): string {
        return hash('sha256', $value);
    }
}
```

`src/ProsettaServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Support\ServiceProvider;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');
        }
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "Start the blank-slate rebuild with config, settings and a testbench harness" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 2: The lean, compatible Locale

**Files:**
- Create: `database/migrations/2026_09_22_000100_create_prosetta_locales_table.php`, `src/Models/Locale.php`, `src/Data/LocaleDescriptor.php`, `src/Support/LocaleCode.php`, `tests/Feature/Models/LocaleTest.php`, `tests/Unit/Support/LocaleCodeTest.php`
- Modify: `tests/TestCase.php`

**Interfaces:**
- Consumes: `Settings::table('locales')`.
- Produces:
  - `Locale` (`locale_initials`, `english_name`, `native_name`, `script`, `rtl`, `active`, `translated`, `is_default`, `sort_order`) with scopes `active()`, `default()`, `ordered()`, `targets()`; statics `getActiveCodes()`, `getDefault()`, `getDefaultCode()`, `findByCode()`; methods `setAsDefault()`, `toggleActive()`, `getDisplayName()`, `isRtl()`, `getDirection()`, `toDescriptor(): LocaleDescriptor`; accessor `code`.
  - `LocaleDescriptor(string $code, string $englishName, string $nativeName, ?string $script = null, bool $rtl = false)` with `toArray()`.
  - `LocaleCode::language(string): string`, `LocaleCode::isVariantOf(string $code, string $of): bool`.
  - `TestCase::seedLocales()` (en active default, es active, ar active rtl, en_GB translated, fr inactive), `TestCase::user(string $id = 'user-1'): GenericUser`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/TestCase.php`:

```php
<?php

namespace LonelyLights\Prosetta\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\ProsettaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra {
    use RefreshDatabase;

    protected function getPackageProviders($app): array {
        return [ProsettaServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /** en is the source; es, ar and en_GB are targets; fr is neither offered nor translated. */
    protected function seedLocales(): void {
        foreach ([
            ['en', 'English', 'English', 'Latin', false, true, false, true, 1],
            ['es', 'Spanish', 'Español', 'Latin', false, true, false, false, 2],
            ['ar', 'Arabic', 'العربية', 'Arabic', true, true, false, false, 3],
            ['fr', 'French', 'Français', 'Latin', false, false, false, false, 6],
            ['en_GB', 'British English', 'English (United Kingdom)', 'Latin', false, false, true, false, 101],
        ] as [$code, $english, $native, $script, $rtl, $active, $translated, $default, $sort]) {
            Locale::query()->create([
                'locale_initials' => $code, 'english_name' => $english, 'native_name' => $native, 'script' => $script,
                'rtl' => $rtl, 'active' => $active, 'translated' => $translated, 'is_default' => $default, 'sort_order' => $sort,
            ]);
        }
    }

    protected function user(string $id = 'user-1'): GenericUser {
        return new GenericUser(['id' => $id]);
    }
}
```

`tests/Feature/Models/LocaleTest.php`:

```php
<?php

use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Models\Locale;

beforeEach(fn () => $this->seedLocales());

it('stores translated separately from active', function () {
    $gb = Locale::findByCode('en_GB');

    expect($gb->translated)->toBeTrue()->and($gb->active)->toBeFalse();
});

it('targets every locale that is active or translated, in sort order', function () {
    expect(Locale::query()->targets()->ordered()->pluck('locale_initials')->all())->toBe(['en', 'es', 'ar', 'en_GB']);
});

it('refuses to change a locale code', function () {
    $es = Locale::findByCode('es');
    $es->locale_initials = 'es-ES';

    expect(fn () => $es->save())->toThrow(LogicException::class, 'immutable');
});

it('still allows changing everything else', function () {
    Locale::findByCode('es')->update(['english_name' => 'Castilian', 'active' => false]);

    expect(Locale::findByCode('es')->english_name)->toBe('Castilian');
});

it('reads its table name from config', function () {
    config()->set('prosetta.table_names.locales', 'custom_locales');

    expect((new Locale)->getTable())->toBe('custom_locales');
});

it('describes itself without exposing the model', function () {
    expect(Locale::findByCode('ar')->toDescriptor())->toEqual(new LocaleDescriptor('ar', 'Arabic', 'العربية', 'Arabic', true));
});

it('moves the default and activates the new default', function () {
    $fr = Locale::findByCode('fr');
    $fr->setAsDefault();

    expect(Locale::getDefaultCode())->toBe('fr')
        ->and($fr->fresh()->active)->toBeTrue()
        ->and(Locale::query()->default()->count())->toBe(1);
});

it('will not deactivate the default locale', function () {
    expect(Locale::findByCode('en')->toggleActive())->toBeFalse()
        ->and(Locale::findByCode('en')->active)->toBeTrue();
});

it('keeps its presentation helpers', function () {
    $ar = Locale::findByCode('ar');

    expect($ar->code)->toBe('ar')
        ->and($ar->getDisplayName())->toBe('العربية (Arabic)')
        ->and($ar->getDirection())->toBe('rtl')
        ->and(Locale::findByCode('en')->getDisplayName())->toBe('English')
        ->and(Locale::getActiveCodes())->toBe(['en', 'es', 'ar']);
});

it('is open to subclassing', function () {
    expect((new ReflectionClass(Locale::class))->isFinal())->toBeFalse();
});
```

`tests/Unit/Support/LocaleCodeTest.php`:

```php
<?php

use LonelyLights\Prosetta\Support\LocaleCode;

it('reads the language subtag', function (string $code, string $language) {
    expect(LocaleCode::language($code))->toBe($language);
})->with([
    ['en', 'en'], ['en_GB', 'en'], ['zh-CN', 'zh'], ['ca-ES-valencia', 'ca'], ['PT_br', 'pt'],
]);

it('knows a regional variant of the same language', function () {
    expect(LocaleCode::isVariantOf('en_GB', 'en'))->toBeTrue()
        ->and(LocaleCode::isVariantOf('en_US', 'en_GB'))->toBeTrue()
        ->and(LocaleCode::isVariantOf('en', 'en'))->toBeFalse()
        ->and(LocaleCode::isVariantOf('es', 'en'))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Models/LocaleTest.php tests/Unit/Support/LocaleCodeTest.php`
Expected: FAIL: classes `LonelyLights\Prosetta\Models\Locale` and `LonelyLights\Prosetta\Support\LocaleCode` not found (and no migrations yet).

- [ ] **Step 3: Implement**

`database/migrations/2026_09_22_000100_create_prosetta_locales_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Locales Prosetta knows. "active" means offered to members; "translated"
 * means Prosetta maintains the locale even when members cannot pick it.
 * Codes match lang folder names exactly and never change once created.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('locales'), function (Blueprint $table): void {
            $table->id();
            $table->string('locale_initials', 35)->unique();
            $table->string('english_name');
            $table->string('native_name');
            $table->string('script')->nullable();
            $table->boolean('rtl')->default(false);
            $table->boolean('active')->default(false);
            $table->boolean('translated')->default(false);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('active');
            $table->index('translated');
            $table->index('is_default');
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('locales'));
    }
};
```

`src/Data/LocaleDescriptor.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/** What translation needs to know about a locale, with no database attached. */
final readonly class LocaleDescriptor {
    public function __construct(
        public string $code,
        public string $englishName,
        public string $nativeName,
        public ?string $script = null,
        public bool $rtl = false,
    ) {}

    /** @return array{code: string, englishName: string, nativeName: string, script: string|null, rtl: bool} */
    public function toArray(): array {
        return ['code' => $this->code, 'englishName' => $this->englishName, 'nativeName' => $this->nativeName, 'script' => $this->script, 'rtl' => $this->rtl];
    }
}
```

`src/Support/LocaleCode.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** Locale codes are stored exactly as folders name them; these helpers read them loosely. */
final class LocaleCode {
    public static function language(string $code): string {
        return strtolower((string) (preg_split('/[-_]/', $code)[0] ?? $code));
    }

    public static function isVariantOf(string $code, string $of): bool {
        return $code !== $of && self::language($code) === self::language($of);
    }
}
```

`src/Models/Locale.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Support\Settings;

/**
 * A locale Prosetta knows. "active" means offered to members; "translated"
 * means Prosetta maintains its strings even when members cannot pick it.
 * Codes are immutable: translations, lang folders and host records all
 * refer to a locale by its code. Hosts attach their own language data with
 * Locale::resolveRelationUsing() or by subclassing through config.
 *
 * @property int $id
 * @property string $locale_initials
 * @property string $english_name
 * @property string $native_name
 * @property string|null $script
 * @property bool $rtl
 * @property bool $active
 * @property bool $translated
 * @property bool $is_default
 * @property int $sort_order
 * @property-read string $code
 *
 * @method static Builder<static> active()
 * @method static Builder<static> default()
 * @method static Builder<static> ordered()
 * @method static Builder<static> targets()
 */
class Locale extends Model {
    protected $fillable = [
        'locale_initials', 'english_name', 'native_name', 'script', 'rtl',
        'active', 'translated', 'is_default', 'sort_order',
    ];

    protected $casts = [
        'rtl' => 'boolean',
        'active' => 'boolean',
        'translated' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getTable(): string {
        return Settings::table('locales');
    }

    protected static function booted(): void {
        static::updating(function (Locale $locale): void {
            if ($locale->isDirty('locale_initials')) {
                throw new LogicException(sprintf(
                    'Locale codes are immutable; cannot change [%s] to [%s].',
                    $locale->getOriginal('locale_initials'),
                    $locale->locale_initials,
                ));
            }
        });
    }

    public function scopeActive(Builder $query): Builder {
        return $query->where('active', true);
    }

    public function scopeDefault(Builder $query): Builder {
        return $query->where('is_default', true);
    }

    public function scopeOrdered(Builder $query): Builder {
        return $query->orderBy('sort_order')->orderBy('english_name');
    }

    /** Locales Prosetta maintains: offered to members or explicitly translated. */
    public function scopeTargets(Builder $query): Builder {
        return $query->where(fn (Builder $inner) => $inner->where('active', true)->orWhere('translated', true));
    }

    /** @return list<string> */
    public static function getActiveCodes(): array {
        return static::query()->active()->ordered()->pluck('locale_initials')->all();
    }

    public static function getDefault(): ?static {
        return static::query()->default()->first();
    }

    public static function getDefaultCode(): ?string {
        return static::getDefault()?->locale_initials;
    }

    public static function findByCode(string $code): ?static {
        return static::query()->where('locale_initials', $code)->first();
    }

    public function setAsDefault(): bool {
        static::query()->where('is_default', true)->whereKeyNot($this->getKey())->update(['is_default' => false]);

        return $this->update(['is_default' => true, 'active' => true]);
    }

    public function toggleActive(): bool {
        if ($this->is_default && $this->active) {
            return false;
        }

        return $this->update(['active' => ! $this->active]);
    }

    public function getCodeAttribute(): string {
        return $this->locale_initials;
    }

    public function getDisplayName(): string {
        return $this->native_name === $this->english_name
            ? $this->english_name
            : "{$this->native_name} ({$this->english_name})";
    }

    public function isRtl(): bool {
        return $this->rtl;
    }

    public function getDirection(): string {
        return $this->rtl ? 'rtl' : 'ltr';
    }

    public function toDescriptor(): LocaleDescriptor {
        return new LocaleDescriptor($this->locale_initials, $this->english_name, $this->native_name, $this->script, $this->rtl);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS (all tests so far).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add the lean Locale with a translated flag and immutable codes" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 3: Key references

**Files:**
- Create: `src/Support/KeyRef.php`, `tests/Unit/Support/KeyRefTest.php`

**Interfaces:**
- Produces: `KeyRef(string $namespace, string $group, string $key)` readonly; constants `KeyRef::ROOT = '*'`, `KeyRef::JSON_GROUP = '*'`; `KeyRef::parse(string $ref): KeyRef` (throws `InvalidArgumentException`); `->isJson(): bool`; `->toString(): string`; `Stringable`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Support/KeyRefTest.php`:

```php
<?php

use LonelyLights\Prosetta\Support\KeyRef;

it('parses a module key', function () {
    $ref = KeyRef::parse('identity::onboarding.toast.accessCode.inUse');

    expect($ref->namespace)->toBe('identity')
        ->and($ref->group)->toBe('onboarding')
        ->and($ref->key)->toBe('toast.accessCode.inUse');
});

it('parses a root key and a nested-folder group', function () {
    expect(KeyRef::parse('auth.failed'))->toEqual(new KeyRef('*', 'auth', 'failed'))
        ->and(KeyRef::parse('admin/settings.steps.0'))->toEqual(new KeyRef('*', 'admin/settings', 'steps.0'));
});

it('keeps dots and double colons inside JSON keys', function () {
    $ref = KeyRef::parse('json:Version 2.0 is ready. See docs::intro');

    expect($ref)->toEqual(new KeyRef('*', '*', 'Version 2.0 is ready. See docs::intro'))
        ->and($ref->isJson())->toBeTrue();
});

it('round-trips every form', function (string $ref) {
    expect(KeyRef::parse($ref)->toString())->toBe($ref)
        ->and((string) KeyRef::parse($ref))->toBe($ref);
})->with([
    'identity::onboarding.toast.accessCode.capReached',
    'auth.throttle',
    'admin/settings.title',
    'json:Save changes',
]);

it('rejects references it cannot place', function (string $ref) {
    expect(fn () => KeyRef::parse($ref))->toThrow(InvalidArgumentException::class);
})->with(['auth', '::auth.failed', 'identity::onboarding', '.failed', 'auth.', 'json:']);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Support/KeyRefTest.php`
Expected: FAIL: class `LonelyLights\Prosetta\Support\KeyRef` not found.

- [ ] **Step 3: Implement**

`src/Support/KeyRef.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

use InvalidArgumentException;
use Stringable;

/**
 * A key in Laravel's own notation: "namespace::group.key", "group.key" for the
 * root lang folder, or "json:Whole sentence." for JSON strings. The group ends
 * at the first dot; groups may contain "/" (nested folders) but never ".".
 */
final readonly class KeyRef implements Stringable {
    public const ROOT = '*';
    public const JSON_GROUP = '*';
    private const JSON_PREFIX = 'json:';

    public function __construct(
        public string $namespace,
        public string $group,
        public string $key,
    ) {}

    public static function parse(string $ref): self {
        if (str_starts_with($ref, self::JSON_PREFIX)) {
            $key = substr($ref, strlen(self::JSON_PREFIX));

            if ($key === '') {
                throw new InvalidArgumentException('A JSON key reference needs the key after "json:".');
            }

            return new self(self::ROOT, self::JSON_GROUP, $key);
        }

        $namespace = self::ROOT;
        $rest = $ref;

        if (str_contains($ref, '::')) {
            [$namespace, $rest] = explode('::', $ref, 2);
        }

        $dot = strpos($rest, '.');

        if ($namespace === '' || $dot === false || $dot === 0 || $dot === strlen($rest) - 1) {
            throw new InvalidArgumentException("[$ref] is not a key reference; expected group.key, namespace::group.key or json:Key.");
        }

        return new self($namespace, substr($rest, 0, $dot), substr($rest, $dot + 1));
    }

    public function isJson(): bool {
        return $this->group === self::JSON_GROUP;
    }

    public function toString(): string {
        if ($this->isJson()) {
            return self::JSON_PREFIX.$this->key;
        }

        $prefix = $this->namespace === self::ROOT ? '' : $this->namespace.'::';

        return $prefix.$this->group.'.'.$this->key;
    }

    public function __toString(): string {
        return $this->toString();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Support/KeyRefTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add KeyRef for Laravel-style key references" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 4: PlaceholderGuard

**Files:**
- Create: `src/Guard/Severity.php`, `src/Guard/Issue.php`, `src/Guard/Placeholders.php`, `src/Guard/PluralForms.php`, `src/Guard/PlaceholderGuard.php`, `tests/Unit/Guard/PlaceholderGuardTest.php`, `tests/Unit/Guard/PluralFormsTest.php`

**Interfaces:**
- Produces:
  - `enum Severity: string { Error = 'error'; Warning = 'warning' }`
  - `Issue(string $code, Severity $severity, string $message)` with `Issue::error()`, `Issue::warning()`, `->isBlocking()`, `->toArray()`, `Issue::fromArray(array)`, `Issue::store(list<Issue>): ?array`, `Issue::anyBlocking(?array $stored): bool`
  - `Placeholders::extract(string): list<string>` (sorted, with repeats), `Placeholders::unique(string): list<string>`
  - `PluralForms::count(string $locale): int`
  - `PlaceholderGuard::check(string $source, string $candidate, string $locale): list<Issue>`
  - Issue codes: `empty_value`, `placeholder_missing`, `placeholder_case`, `placeholder_unexpected`, `placeholder_count` (warning), `plural_range_missing`, `plural_missing`, `plural_segments_excess`, `plural_segments_differ` (warning), `html_mismatch`, `missing_value` (used by Task 12).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Guard/PluralFormsTest.php`:

```php
<?php

use LonelyLights\Prosetta\Guard\PluralForms;

it('derives plural form counts from Laravel', function (string $locale, int $forms) {
    expect(PluralForms::count($locale))->toBe($forms);
})->with([
    ['en', 2], ['en_GB', 2], ['es', 2], ['ar', 6], ['zh-CN', 1], ['ja', 1], ['ru', 3],
]);
```

`tests/Unit/Guard/PlaceholderGuardTest.php`:

```php
<?php

use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Guard\Placeholders;
use LonelyLights\Prosetta\Guard\Severity;

function codes(array $issues): array {
    return array_map(fn (Issue $issue) => $issue->code, $issues);
}

it('extracts placeholders, keeping repeats and stopping at punctuation', function () {
    expect(Placeholders::extract('Registration has a :minutes-minute limit; :name, :name!'))
        ->toBe([':minutes', ':name', ':name'])
        ->and(Placeholders::unique('At 10:30 visit https://x.test'))->toBe([]);
});

it('passes a faithful translation', function () {
    expect((new PlaceholderGuard)->check(
        'Registration has a :minutes-minute limit, so the access code was released.',
        'El registro tiene un límite de :minutes minutos, así que el código de acceso se liberó.',
        'es',
    ))->toBe([]);
});

it('flags a missing placeholder', function () {
    expect(codes((new PlaceholderGuard)->check('Welcome, :name!', '¡Bienvenido!', 'es')))->toBe(['placeholder_missing']);
});

it('flags a placeholder whose case changed, because Laravel formats by case', function () {
    $issues = (new PlaceholderGuard)->check('Welcome, :name!', '¡Bienvenido, :Name!', 'es');

    expect(codes($issues))->toBe(['placeholder_case'])
        ->and($issues[0]->message)->toContain(':Name');
});

it('flags an invented placeholder', function () {
    expect(codes((new PlaceholderGuard)->check('Welcome!', '¡Bienvenido, :nombre!', 'es')))->toBe(['placeholder_unexpected']);
});

it('warns when a placeholder repeats a different number of times', function () {
    $issues = (new PlaceholderGuard)->check(':name and :name', ':name', 'es');

    expect(codes($issues))->toBe(['placeholder_count'])
        ->and($issues[0]->severity)->toBe(Severity::Warning);
});

it('flags an empty translation', function () {
    expect(codes((new PlaceholderGuard)->check('Save', '  ', 'es')))->toBe(['empty_value']);
});

it('requires explicit plural ranges to survive', function () {
    $guard = new PlaceholderGuard;
    $source = '{0} No apples|{1} One apple|[2,*] :count apples';

    expect($guard->check($source, '{0} Sin manzanas|{1} Una manzana|[2,*] :count manzanas', 'es'))->toBe([])
        ->and(codes($guard->check($source, '{0} Sin manzanas|Una manzana|:count manzanas', 'es')))->toBe(['plural_range_missing']);
});

it('checks plain plural segments against the target language', function () {
    $guard = new PlaceholderGuard;
    $source = 'One apple|:count apples';

    expect($guard->check($source, 'Una manzana|:count manzanas', 'es'))->toBe([])
        ->and(codes($guard->check($source, ':count manzanas', 'es')))->toBe(['plural_missing'])
        ->and(codes($guard->check($source, 'a|b|:count c', 'es')))->toBe(['plural_segments_excess'])
        ->and(codes($guard->check($source, 'a|b|c|d|e|:count f', 'ar')))->toBe(['plural_segments_differ'])
        ->and(codes($guard->check($source, ':count 个苹果', 'zh-CN')))->toBe(['plural_segments_differ']);
});

it('requires HTML tags to survive in order', function () {
    $guard = new PlaceholderGuard;
    $source = 'Read the <a href=":url">terms</a> first.';

    expect($guard->check($source, 'Lee los <a href=":url">términos</a> primero.', 'es'))->toBe([])
        ->and(codes($guard->check($source, 'Lee los términos primero. :url', 'es')))->toBe(['html_mismatch']);
});

it('stores issues as arrays and knows which block', function () {
    $stored = Issue::store([Issue::warning('placeholder_count', 'x'), Issue::error('placeholder_missing', 'y')]);

    expect($stored)->toBe([
        ['code' => 'placeholder_count', 'severity' => 'warning', 'message' => 'x'],
        ['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'y'],
    ])
        ->and(Issue::anyBlocking($stored))->toBeTrue()
        ->and(Issue::anyBlocking([$stored[0]]))->toBeFalse()
        ->and(Issue::anyBlocking(null))->toBeFalse()
        ->and(Issue::store([]))->toBeNull()
        ->and(Issue::fromArray($stored[1])->isBlocking())->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Guard`
Expected: FAIL: classes in `LonelyLights\Prosetta\Guard` not found.

- [ ] **Step 3: Implement**

`src/Guard/Severity.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

enum Severity: string {
    case Error = 'error';
    case Warning = 'warning';
}
```

`src/Guard/Issue.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/** One problem with a translation. Only errors block approval and export. */
final readonly class Issue {
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $message,
    ) {}

    public static function error(string $code, string $message): self {
        return new self($code, Severity::Error, $message);
    }

    public static function warning(string $code, string $message): self {
        return new self($code, Severity::Warning, $message);
    }

    public function isBlocking(): bool {
        return $this->severity === Severity::Error;
    }

    /** @return array{code: string, severity: string, message: string} */
    public function toArray(): array {
        return ['code' => $this->code, 'severity' => $this->severity->value, 'message' => $this->message];
    }

    /** @param array{code: string, severity: string, message: string} $data */
    public static function fromArray(array $data): self {
        return new self($data['code'], Severity::from($data['severity']), $data['message']);
    }

    /**
     * @param list<self> $issues
     * @return list<array{code: string, severity: string, message: string}>|null
     */
    public static function store(array $issues): ?array {
        return $issues === [] ? null : array_map(fn (self $issue) => $issue->toArray(), $issues);
    }

    /** @param list<array{code: string, severity: string, message: string}>|null $stored */
    public static function anyBlocking(?array $stored): bool {
        foreach ($stored ?? [] as $row) {
            if (($row['severity'] ?? null) === Severity::Error->value) {
                return true;
            }
        }

        return false;
    }
}
```

`src/Guard/Placeholders.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/** Laravel's ":name" replacement tokens. Case matters: :name, :Name and :NAME format differently. */
final class Placeholders {
    private const PATTERN = '/:[A-Za-z_][A-Za-z0-9_]*/';

    /** @return list<string> */
    public static function extract(string $value): array {
        preg_match_all(self::PATTERN, $value, $matches);
        $tokens = $matches[0];
        sort($tokens);

        return $tokens;
    }

    /** @return list<string> */
    public static function unique(string $value): array {
        return array_values(array_unique(self::extract($value)));
    }
}
```

`src/Guard/PluralForms.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

use Illuminate\Translation\MessageSelector;
use LonelyLights\Prosetta\Support\LocaleCode;

/** How many plural forms a language has, derived from Laravel's own plural rules. */
final class PluralForms {
    private const SAMPLES = [1.5, 1000, 1001, 1002, 1011, 1021, 1100];

    public static function count(string $locale): int {
        $selector = new MessageSelector;
        $language = LocaleCode::language($locale);
        $highest = 0;

        foreach ([...range(0, 200), ...self::SAMPLES] as $number) {
            $highest = max($highest, $selector->getPluralIndex($language, $number));
        }

        return $highest + 1;
    }
}
```

`src/Guard/PlaceholderGuard.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * Checks a translation against its source: placeholders (exact case),
 * plural segments and ranges, and HTML tags. Runs on every AI result, every
 * manual edit and every imported value.
 */
final class PlaceholderGuard {
    private const RANGE = '/^\s*[\{\[][-?\d|*,\.*]*[\}\]]/';

    /** @return list<Issue> */
    public function check(string $source, string $candidate, string $locale): array {
        if (trim($source) !== '' && trim($candidate) === '') {
            return [Issue::error('empty_value', 'The translation is empty.')];
        }

        return [
            ...$this->placeholders($source, $candidate),
            ...$this->plurals($source, $candidate, $locale),
            ...$this->html($source, $candidate),
        ];
    }

    /** @return list<Issue> */
    private function placeholders(string $source, string $candidate): array {
        $expected = Placeholders::unique($source);
        $actual = Placeholders::unique($candidate);
        $issues = [];

        foreach (array_diff($expected, $actual) as $token) {
            $recased = array_values(array_filter($actual, fn (string $found) => $found !== $token && strcasecmp($found, $token) === 0));

            $issues[] = $recased !== []
                ? Issue::error('placeholder_case', "Placeholder $token changed case to {$recased[0]}; Laravel treats case as formatting.")
                : Issue::error('placeholder_missing', "Placeholder $token is missing.");
        }

        $expectedLower = array_map('strtolower', $expected);

        foreach (array_diff($actual, $expected) as $token) {
            if (! in_array(strtolower($token), $expectedLower, true)) {
                $issues[] = Issue::error('placeholder_unexpected', "Placeholder $token is not in the source.");
            }
        }

        if ($issues === [] && ! str_contains($source, '|') && Placeholders::extract($source) !== Placeholders::extract($candidate)) {
            $issues[] = Issue::warning('placeholder_count', 'A placeholder appears a different number of times than in the source.');
        }

        return $issues;
    }

    /** @return list<Issue> */
    private function plurals(string $source, string $candidate, string $locale): array {
        if (! str_contains($source, '|')) {
            return [];
        }

        $sourceSegments = explode('|', $source);
        $candidateSegments = explode('|', $candidate);
        $ranges = $this->ranges($sourceSegments);

        if ($ranges !== []) {
            $missing = array_diff($ranges, $this->ranges($candidateSegments));

            return $missing === [] ? [] : [Issue::error('plural_range_missing', 'Plural ranges missing: '.implode(', ', $missing).'.')];
        }

        $forms = PluralForms::count($locale);
        $count = count($candidateSegments);

        if ($count === 1 && $forms > 1) {
            return [Issue::error('plural_missing', "The source has plural forms; $locale needs up to $forms.")];
        }

        if ($count > $forms) {
            return [Issue::error('plural_segments_excess', "$count plural segments, but $locale has only $forms forms.")];
        }

        if ($count !== count($sourceSegments)) {
            return [Issue::warning('plural_segments_differ', "$count plural segments where the source has ".count($sourceSegments).'.')];
        }

        return [];
    }

    /**
     * @param list<string> $segments
     * @return list<string>
     */
    private function ranges(array $segments): array {
        $ranges = [];

        foreach ($segments as $segment) {
            if (preg_match(self::RANGE, $segment, $match) === 1) {
                $ranges[] = trim($match[0]);
            }
        }

        return $ranges;
    }

    /** @return list<Issue> */
    private function html(string $source, string $candidate): array {
        $expected = $this->tags($source);

        if ($expected === [] || $expected === $this->tags($candidate)) {
            return [];
        }

        return [Issue::error('html_mismatch', 'HTML tags differ from the source; expected '.implode(' ', $expected).'.')];
    }

    /** @return list<string> */
    private function tags(string $value): array {
        preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)/', $value, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => $match[1].strtolower($match[2]), $matches);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Guard`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add PlaceholderGuard for placeholders, plurals and HTML" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 5: Workflow schema, models and derived states

**Files:**
- Create:
  - `database/migrations/2026_09_22_000200_create_prosetta_files_table.php`, `…000300_create_prosetta_keys_table.php`, `…000400_create_prosetta_translations_table.php`, `…000500_create_prosetta_reviews_table.php`
  - `src/Enums/{FileFormat,KeyKind,TranslationStatus,TranslationOrigin,ReviewAction}.php`
  - `src/Models/{TranslationFile,TranslationKey,Translation,TranslationReview}.php`
  - `src/Support/WorkState.php`
  - `tests/Feature/Models/WorkflowModelsTest.php`, `tests/Feature/Support/WorkStateTest.php`

**Interfaces:**
- Consumes: `Settings`, `Fingerprint`, `KeyRef`, `Issue::anyBlocking()`.
- Produces:
  - Enums: `FileFormat::{Php='php',Json='json'}`, `KeyKind::{File='file',Content='content'}`, `TranslationStatus::{Draft='draft',NeedsReview='needs_review',Approved='approved',Rejected='rejected'}`, `TranslationOrigin::{Manual='manual',Ai='ai',Imported='imported'}`, `ReviewAction::{Submitted='submitted',Approved='approved',Rejected='rejected',Edited='edited',Imported='imported'}`.
  - `TranslationFile` (`namespace`, `group`, `format`) with `keys(): HasMany`.
  - `TranslationKey` (`file_id`, `kind`, `key`, `key_hash` set automatically on save, `source_value`, `source_hash`, `placeholders` array, `context`, `max_length`, `obsolete_at`) with `file()`, `translations()`, scopes `current()` and `withKey(string $key)`, `ref(): KeyRef`, `isObsolete(): bool`.
  - `Translation` (all spec columns) with `key()`, `reviews()`, `hasBlockingIssues(): bool`.
  - `TranslationReview` (`translation_id`, `reviewer_id`, `action`, `previous_value`, `new_value`, `notes`) with `translation()`.
  - `WorkState::isMissing(TranslationKey, ?Translation)`, `::isStale(...)` (live value stale), `::hasCurrentCandidate(...)`, `::needsWork(...)`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Models/WorkflowModelsTest.php`:

```php
<?php

use Illuminate\Database\QueryException;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;

function makeKey(string $namespace = 'identity', string $group = 'onboarding', string $key = 'toast.accessCode.inUse', string $value = 'In use.'): TranslationKey {
    $file = TranslationFile::query()->firstOrCreate(['namespace' => $namespace, 'group' => $group], ['format' => FileFormat::Php]);

    return TranslationKey::query()->create([
        'file_id' => $file->id, 'key' => $key, 'source_value' => $value, 'source_hash' => Fingerprint::of($value),
    ]);
}

it('links files, keys, translations and reviews', function () {
    $key = makeKey();
    $translation = $key->translations()->create(['locale' => 'es', 'value' => 'En uso.', 'source_hash' => $key->source_hash]);
    $review = $translation->reviews()->create(['reviewer_id' => 'user-1', 'action' => ReviewAction::Edited, 'new_value' => 'En uso.']);

    expect($key->file->group)->toBe('onboarding')
        ->and($translation->key->is($key))->toBeTrue()
        ->and($review->translation->is($translation))->toBeTrue()
        ->and($review->action)->toBe(ReviewAction::Edited);
});

it('casts to enums and defaults new translations to manual drafts', function () {
    $key = makeKey();
    $translation = $key->translations()->create(['locale' => 'es', 'value' => 'x'])->fresh();

    expect($key->kind)->toBe(KeyKind::File)
        ->and($key->file->format)->toBe(FileFormat::Php)
        ->and($translation->status)->toBe(TranslationStatus::Draft)
        ->and($translation->origin)->toBe(TranslationOrigin::Manual);
});

it('builds a KeyRef from the key and its file', function () {
    expect(makeKey()->ref()->toString())->toBe('identity::onboarding.toast.accessCode.inUse')
        ->and(makeKey('*', '*', 'Save changes')->ref()->toString())->toBe('json:Save changes');
});

it('fingerprints the key so long JSON sentences stay unique and findable', function () {
    $long = str_repeat('A long sentence. ', 60);
    $key = makeKey('*', '*', $long);

    expect($key->key_hash)->toBe(Fingerprint::of($long))
        ->and(TranslationKey::query()->withKey($long)->first()?->is($key))->toBeTrue();
});

it('enforces one file per namespace and group, one key per file, one translation per locale', function () {
    $key = makeKey();
    $key->translations()->create(['locale' => 'es', 'value' => 'a']);

    expect(fn () => TranslationFile::query()->create(['namespace' => 'identity', 'group' => 'onboarding', 'format' => FileFormat::Php]))->toThrow(QueryException::class)
        ->and(fn () => TranslationKey::query()->create(['file_id' => $key->file_id, 'key' => $key->key, 'source_value' => 'x', 'source_hash' => 'x']))->toThrow(QueryException::class)
        ->and(fn () => $key->translations()->create(['locale' => 'es', 'value' => 'b']))->toThrow(QueryException::class);
});

it('reads every table name from config', function () {
    config()->set('prosetta.table_names.keys', 'custom_keys');

    expect((new TranslationKey)->getTable())->toBe('custom_keys')
        ->and((new Translation)->getTable())->toBe('prosetta_translations');
});

it('knows when a translation carries blocking issues', function () {
    $translation = new Translation(['issues' => [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'x']]]);

    expect($translation->hasBlockingIssues())->toBeTrue()
        ->and((new Translation)->hasBlockingIssues())->toBeFalse();
});

it('scopes to current keys', function () {
    makeKey(key: 'a');
    makeKey(key: 'b')->update(['obsolete_at' => now()]);

    expect(TranslationKey::query()->current()->pluck('key')->all())->toBe(['a']);
});
```

`tests/Feature/Support/WorkStateTest.php`:

```php
<?php

use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\WorkState;

function stateKey(): TranslationKey {
    $file = TranslationFile::query()->create(['namespace' => '*', 'group' => 'auth', 'format' => FileFormat::Php]);

    return TranslationKey::query()->create(['file_id' => $file->id, 'key' => 'failed', 'source_value' => 'Failed.', 'source_hash' => 'h2']);
}

it('classifies every state a key can be in for one locale', function (?array $attributes, bool $missing, bool $stale, bool $candidate, bool $work) {
    $key = stateKey();
    $translation = $attributes === null ? null : new Translation($attributes);

    expect(WorkState::isMissing($key, $translation))->toBe($missing)
        ->and(WorkState::isStale($key, $translation))->toBe($stale)
        ->and(WorkState::hasCurrentCandidate($key, $translation))->toBe($candidate)
        ->and(WorkState::needsWork($key, $translation))->toBe($work);
})->with([
    'no row' => [null, true, false, false, true],
    'current draft' => [['value' => 'x', 'source_hash' => 'h2', 'status' => TranslationStatus::Draft], false, false, true, false],
    'rejected, nothing approved' => [['value' => 'x', 'source_hash' => 'h2', 'status' => TranslationStatus::Rejected], true, false, false, true],
    'approved and current' => [['value' => 'x', 'source_hash' => 'h2', 'approved_value' => 'x', 'approved_source_hash' => 'h2', 'status' => TranslationStatus::Approved], false, false, false, false],
    'approved but stale' => [['value' => 'x', 'source_hash' => 'h1', 'approved_value' => 'x', 'approved_source_hash' => 'h1', 'status' => TranslationStatus::Approved], false, true, false, true],
    'stale approved, fresh draft waiting' => [['value' => 'y', 'source_hash' => 'h2', 'approved_value' => 'x', 'approved_source_hash' => 'h1', 'status' => TranslationStatus::Draft], false, true, true, false],
    'stale draft, nothing approved' => [['value' => 'x', 'source_hash' => 'h1', 'status' => TranslationStatus::Draft], false, false, false, true],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Models/WorkflowModelsTest.php tests/Feature/Support/WorkStateTest.php`
Expected: FAIL: the enum and model classes aren't found.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_09_22_000200_create_prosetta_files_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/** One row per lang file group: namespace '*' is the root lang folder, group '*' is its JSON file. */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('files'), function (Blueprint $table): void {
            $table->id();
            $table->string('namespace', 191);
            $table->string('group', 191);
            $table->string('format', 10);
            $table->timestamps();

            $table->unique(['namespace', 'group']);
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('files'));
    }
};
```

`database/migrations/2026_09_22_000300_create_prosetta_keys_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * The canonical keys, read from the source locale's files. key_hash carries
 * uniqueness because JSON keys are whole sentences too long to index.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('keys'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')->constrained(Settings::table('files'))->cascadeOnDelete();
            $table->string('kind', 20)->default('file');
            $table->text('key');
            $table->string('key_hash', 64);
            $table->text('source_value');
            $table->string('source_hash', 64);
            $table->json('placeholders')->nullable();
            $table->text('context')->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->timestamp('obsolete_at')->nullable();
            $table->timestamps();

            $table->unique(['file_id', 'key_hash']);
            $table->index('obsolete_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('keys'));
    }
};
```

`database/migrations/2026_09_22_000400_create_prosetta_translations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * One row per key and locale. value/source_hash is the candidate under
 * review; approved_value/approved_source_hash is what users see. The two
 * hashes against the key's source_hash decide what is stale.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('translations'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('key_id')->constrained(Settings::table('keys'))->cascadeOnDelete();
            $table->string('locale', 35);
            $table->text('value')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->text('approved_value')->nullable();
            $table->string('approved_source_hash', 64)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('origin', 20)->default('manual');
            $table->json('issues')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('ai_invocation_id')->nullable();
            $table->string('exported_hash', 64)->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['key_id', 'locale']);
            $table->index(['locale', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('translations'));
    }
};
```

`database/migrations/2026_09_22_000500_create_prosetta_reviews_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/** The audit trail. reviewer_id is a plain string so int and UUID users both fit; null means the system. */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('reviews'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('translation_id')->constrained(Settings::table('translations'))->cascadeOnDelete();
            $table->string('reviewer_id')->nullable()->index();
            $table->string('action', 20);
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('reviews'));
    }
};
```

- [ ] **Step 4: Write the enums**

`src/Enums/FileFormat.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum FileFormat: string {
    case Php = 'php';
    case Json = 'json';
}
```

`src/Enums/KeyKind.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

/** "content" is reserved for Eloquent fields (spatie/laravel-translatable), planned after the MVP. */
enum KeyKind: string {
    case File = 'file';
    case Content = 'content';
}
```

`src/Enums/TranslationStatus.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum TranslationStatus: string {
    case Draft = 'draft';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

`src/Enums/TranslationOrigin.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum TranslationOrigin: string {
    case Manual = 'manual';
    case Ai = 'ai';
    case Imported = 'imported';
}
```

`src/Enums/ReviewAction.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum ReviewAction: string {
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Edited = 'edited';
    case Imported = 'imported';
}
```

- [ ] **Step 5: Write the models and WorkState**

`src/Models/TranslationFile.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property string $namespace
 * @property string $group
 * @property FileFormat $format
 */
class TranslationFile extends Model {
    protected $fillable = ['namespace', 'group', 'format'];

    protected $casts = ['format' => FileFormat::class];

    public function getTable(): string {
        return Settings::table('files');
    }

    public function keys(): HasMany {
        return $this->hasMany(Settings::model('key'), 'file_id');
    }
}
```

`src/Models/TranslationKey.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property int $file_id
 * @property KeyKind $kind
 * @property string $key
 * @property string $key_hash
 * @property string $source_value
 * @property string $source_hash
 * @property list<string>|null $placeholders
 * @property string|null $context
 * @property int|null $max_length
 * @property Carbon|null $obsolete_at
 * @property-read TranslationFile $file
 */
class TranslationKey extends Model {
    protected $fillable = ['file_id', 'kind', 'key', 'source_value', 'source_hash', 'placeholders', 'context', 'max_length', 'obsolete_at'];

    protected $casts = [
        'kind' => KeyKind::class,
        'placeholders' => 'array',
        'max_length' => 'integer',
        'obsolete_at' => 'datetime',
    ];

    protected $attributes = ['kind' => 'file'];

    public function getTable(): string {
        return Settings::table('keys');
    }

    protected static function booted(): void {
        static::saving(function (TranslationKey $key): void {
            $key->key_hash = Fingerprint::of($key->key);
        });
    }

    public function file(): BelongsTo {
        return $this->belongsTo(Settings::model('file'), 'file_id');
    }

    public function translations(): HasMany {
        return $this->hasMany(Settings::model('translation'), 'key_id');
    }

    public function scopeCurrent(Builder $query): Builder {
        return $query->whereNull($query->qualifyColumn('obsolete_at'));
    }

    public function scopeWithKey(Builder $query, string $key): Builder {
        return $query->where($query->qualifyColumn('key_hash'), Fingerprint::of($key));
    }

    public function isObsolete(): bool {
        return $this->obsolete_at !== null;
    }

    public function ref(): KeyRef {
        return new KeyRef($this->file->namespace, $this->file->group, $this->key);
    }
}
```

`src/Models/Translation.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property int $key_id
 * @property string $locale
 * @property string|null $value
 * @property string|null $source_hash
 * @property string|null $approved_value
 * @property string|null $approved_source_hash
 * @property TranslationStatus $status
 * @property TranslationOrigin $origin
 * @property list<array{code: string, severity: string, message: string}>|null $issues
 * @property string|null $ai_provider
 * @property string|null $ai_model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $ai_invocation_id
 * @property string|null $exported_hash
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read TranslationKey $key
 */
class Translation extends Model {
    protected $fillable = [
        'key_id', 'locale', 'value', 'source_hash', 'approved_value', 'approved_source_hash',
        'status', 'origin', 'issues', 'ai_provider', 'ai_model', 'input_tokens', 'output_tokens',
        'ai_invocation_id', 'exported_hash', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'status' => TranslationStatus::class,
        'origin' => TranslationOrigin::class,
        'issues' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'draft', 'origin' => 'manual'];

    public function getTable(): string {
        return Settings::table('translations');
    }

    public function key(): BelongsTo {
        return $this->belongsTo(Settings::model('key'), 'key_id');
    }

    public function reviews(): HasMany {
        return $this->hasMany(Settings::model('review'), 'translation_id');
    }

    public function hasBlockingIssues(): bool {
        return Issue::anyBlocking($this->issues);
    }
}
```

`src/Models/TranslationReview.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property int $translation_id
 * @property string|null $reviewer_id
 * @property ReviewAction $action
 * @property string|null $previous_value
 * @property string|null $new_value
 * @property string|null $notes
 */
class TranslationReview extends Model {
    protected $fillable = ['translation_id', 'reviewer_id', 'action', 'previous_value', 'new_value', 'notes'];

    protected $casts = ['action' => ReviewAction::class];

    public function getTable(): string {
        return Settings::table('reviews');
    }

    public function translation(): BelongsTo {
        return $this->belongsTo(Settings::model('translation'), 'translation_id');
    }
}
```

`src/Support/WorkState.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;

/** The derived work states. Nothing is stored; everything follows from hashes and statuses. */
final class WorkState {
    /** No approved value and no candidate worth reviewing. */
    public static function isMissing(TranslationKey $key, ?Translation $translation): bool {
        if ($translation === null) {
            return true;
        }

        return $translation->approved_value === null
            && ($translation->value === null || $translation->status === TranslationStatus::Rejected);
    }

    /** The live (approved) value was made from different English than the key has now. */
    public static function isStale(TranslationKey $key, ?Translation $translation): bool {
        return $translation !== null
            && $translation->approved_value !== null
            && $translation->approved_source_hash !== $key->source_hash;
    }

    /** A draft or edit made from the current English is waiting for review. */
    public static function hasCurrentCandidate(TranslationKey $key, ?Translation $translation): bool {
        return $translation !== null
            && $translation->value !== null
            && $translation->source_hash === $key->source_hash
            && in_array($translation->status, [TranslationStatus::Draft, TranslationStatus::NeedsReview], true);
    }

    /** Whether the AI should (re)translate this key for this locale. */
    public static function needsWork(TranslationKey $key, ?Translation $translation): bool {
        if (self::isMissing($key, $translation)) {
            return true;
        }

        if (self::hasCurrentCandidate($key, $translation)) {
            return false;
        }

        if (self::isStale($key, $translation)) {
            return true;
        }

        return $translation->approved_value === null && $translation->source_hash !== $key->source_hash;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add the workflow schema, models and derived work states" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 6: Locale sources

**Files:**
- Create: `src/Contracts/LocaleSource.php`, `src/Locales/DatabaseLocaleSource.php`, `src/Locales/ConfigLocaleSource.php`, `tests/Feature/Locales/LocaleSourceTest.php`
- Modify: `src/ProsettaServiceProvider.php`

**Interfaces:**
- Consumes: `Locale::targets()`, `Locale::toDescriptor()`, `LocaleCode::language()`.
- Produces: `interface LocaleSource { source(): string; targets(): array /* list<LocaleDescriptor> */; find(string $code): ?LocaleDescriptor; }` bound in the container to `config('prosetta.locales.source')`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Locales/LocaleSourceTest.php`:

```php
<?php

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Locales\ConfigLocaleSource;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

it('reads targets from the locales table, excluding the source', function () {
    $this->seedLocales();
    $source = app(LocaleSource::class);

    expect($source)->toBeInstanceOf(DatabaseLocaleSource::class)
        ->and($source->source())->toBe('en')
        ->and(array_map(fn ($l) => $l->code, $source->targets()))->toBe(['es', 'ar', 'en_GB'])
        ->and($source->find('ar')?->rtl)->toBeTrue()
        ->and($source->find('fr')?->englishName)->toBe('French')
        ->and($source->find('xx'))->toBeNull();
});

it('falls back to a config list for apps without the table', function () {
    config()->set('prosetta.locales.source', ConfigLocaleSource::class);
    config()->set('prosetta.locales.fallback', ['en', 'es', 'ar']);
    $source = app(LocaleSource::class);

    expect($source)->toBeInstanceOf(ConfigLocaleSource::class)
        ->and(array_map(fn ($l) => $l->code, $source->targets()))->toBe(['es', 'ar'])
        ->and($source->find('ar')?->rtl)->toBeTrue()
        ->and($source->find('es')?->rtl)->toBeFalse()
        ->and($source->find('de'))->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Locales/LocaleSourceTest.php`
Expected: FAIL: interface `LonelyLights\Prosetta\Contracts\LocaleSource` not found.

- [ ] **Step 3: Implement**

`src/Contracts/LocaleSource.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

use LonelyLights\Prosetta\Data\LocaleDescriptor;

/** Which locales Prosetta works with. Hosts may bind their own. */
interface LocaleSource {
    /** The canonical locale, whose files are read and never written. */
    public function source(): string;

    /** @return list<LocaleDescriptor> every locale Prosetta maintains, excluding the source */
    public function targets(): array;

    public function find(string $code): ?LocaleDescriptor;
}
```

`src/Locales/DatabaseLocaleSource.php`:

```php
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
```

`src/Locales/ConfigLocaleSource.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Locales;

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Support\LocaleCode;
use LonelyLights\Prosetta\Support\Settings;

/** For apps without the locales table: prosetta.locales.fallback lists the codes; names are the codes. */
final class ConfigLocaleSource implements LocaleSource {
    private const RTL = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

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
    private function codes(): array {
        return array_values(array_filter((array) config('prosetta.locales.fallback', []), 'is_string'));
    }

    private function describe(string $code): LocaleDescriptor {
        return new LocaleDescriptor($code, $code, $code, null, in_array(LocaleCode::language($code), self::RTL, true));
    }
}
```

Replace `src/ProsettaServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');

        $this->app->bind(LocaleSource::class, fn (Application $app) => $app->make((string) config('prosetta.locales.source', DatabaseLocaleSource::class)));
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add LocaleSource with database and config implementations" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 7: Root discovery and the lang reader (with the fixture app)

**Files:**
- Create:
  - `src/Exceptions/ProsettaException.php`, `src/Exceptions/LangFileException.php`
  - `src/Support/PathFilter.php`, `src/Discovery/LangRoot.php`, `src/Discovery/RootDiscovery.php`, `src/Discovery/LangReader.php`
  - the fixture tree under `tests/Fixtures/app/` (listed in Step 1)
  - `tests/Feature/Discovery/RootDiscoveryTest.php`, `tests/Feature/Discovery/LangReaderTest.php`
- Modify: `tests/TestCase.php`

**Interfaces:**
- Consumes: `KeyRef::ROOT`, `KeyRef::JSON_GROUP`, `FileFormat`.
- Produces:
  - `ProsettaException extends RuntimeException`; `LangFileException extends ProsettaException` with `::unreadable(string $path, Throwable $e)`, `::notAnArray(string $path)`.
  - `PathFilter::normalize(string): string`, `->excluded(string $path): bool`.
  - `LangRoot(string $namespace, string $path)` with `->isRoot()`.
  - `RootDiscovery::roots(?array $only = null): list<LangRoot>`.
  - `LangReader::groups(LangRoot, string $locale): list<array{group: string, format: FileFormat}>`, `->path(LangRoot, string $locale, string $group, FileFormat): string`, `->read(LangRoot, string $locale, string $group, FileFormat): array<string, string>` (flattened, source order, strings only).
  - `TestCase::useFixtureApp(): string` (returns the temp directory, also kept in `$this->fixture`).

- [ ] **Step 1: Create the fixture app**

`tests/Fixtures/app/lang/en/auth.php`:

```php
<?php

return [
    'failed' => 'These credentials do not match our records.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
];
```

`tests/Fixtures/app/lang/en/messages.php`:

```php
<?php

# Fixture: a placeholder, explicit plural ranges, HTML, and a non-string leaf sync must skip
return [
    'welcome' => 'Welcome, :name!',
    'apples' => '{0} No apples|{1} One apple|[2,*] :count apples',
    'terms' => 'Read the <a href=":url">terms</a> before you continue.',
    'limit' => 25,
];
```

`tests/Fixtures/app/lang/en/admin/settings.php`:

```php
<?php

return [
    'title' => 'Settings',
    'steps' => ['Open the menu', 'Choose a language'],
];
```

`tests/Fixtures/app/lang/en.json`:

```json
{
    "Save changes": "Save changes",
    "Version 2.0 is ready.": "Version 2.0 is ready."
}
```

`tests/Fixtures/app/lang/es/auth.php`:

```php
<?php

return [
    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
];
```

`tests/Fixtures/app/lang/es.json`:

```json
{
    "Save changes": "Guardar cambios"
}
```

`tests/Fixtures/app/lang/en_GB/messages.php`:

```php
<?php

return [
    'welcome' => 'Welcome, :name!',
];
```

`tests/Fixtures/app/modules/Identity/Lang/en/onboarding.php` (Undaunted's real strings):

```php
<?php

return [
    'toast' => [
        'accessCode' => [
            'capReached' => 'Registration has a :minutes-minute limit, so the access code was released. Enter it again to start over.',
            'inUse' => 'This access code is being used to register on another device. If that registration isn\'t finished, it frees up in a few minutes.',
            'timedOut' => 'Your registration paused for more than five minutes, so the access code was released. Enter it again to continue.',
        ],
    ],
];
```

`tests/Fixtures/app/modules/Identity/Lang/es/onboarding.php`:

```php
<?php

return [
    'toast' => [
        'accessCode' => [
            'inUse' => 'Este código de acceso se está usando para registrarse en otro dispositivo. Si ese registro no se completa, quedará libre en unos minutos.',
        ],
    ],
];
```

`tests/Fixtures/app/modules/Legacy/Lang/en/legacy.php`:

```php
<?php

# Fixture: registered as a namespace but listed in exclude_paths, so discovery must skip it
return [
    'old' => 'An excluded string.',
];
```

Replace `tests/TestCase.php` (adds `$fixture`, `useFixtureApp()` and cleanup):

```php
<?php

namespace LonelyLights\Prosetta\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\ProsettaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra {
    use RefreshDatabase;

    /** The temp copy of tests/Fixtures/app for this test, if one was made. */
    protected ?string $fixture = null;

    protected function getPackageProviders($app): array {
        return [ProsettaServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function tearDown(): void {
        if ($this->fixture !== null) {
            (new Filesystem)->deleteDirectory($this->fixture);
            $this->fixture = null;
        }

        parent::tearDown();
    }

    /**
     * Copies the fixture app to a temp directory, points lang_path() at it,
     * registers the identity and legacy module namespaces, and excludes legacy.
     */
    protected function useFixtureApp(): string {
        $files = new Filesystem;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prosetta-'.bin2hex(random_bytes(6));
        $files->copyDirectory(__DIR__.'/Fixtures/app', $directory);
        $directory = str_replace('\\', '/', (string) realpath($directory));
        $this->fixture = $directory;

        $this->app->useLangPath($directory.'/lang');
        $translator = $this->app->make('translator');
        $translator->addNamespace('identity', $directory.'/modules/Identity/Lang');
        $translator->addNamespace('legacy', $directory.'/modules/Legacy/Lang');
        config()->set('prosetta.exclude_paths', ['lang/vendor', 'vendor', $directory.'/modules/Legacy']);

        return $directory;
    }

    /** en is the source; es, ar and en_GB are targets; fr is neither offered nor translated. */
    protected function seedLocales(): void {
        foreach ([
            ['en', 'English', 'English', 'Latin', false, true, false, true, 1],
            ['es', 'Spanish', 'Español', 'Latin', false, true, false, false, 2],
            ['ar', 'Arabic', 'العربية', 'Arabic', true, true, false, false, 3],
            ['fr', 'French', 'Français', 'Latin', false, false, false, false, 6],
            ['en_GB', 'British English', 'English (United Kingdom)', 'Latin', false, false, true, false, 101],
        ] as [$code, $english, $native, $script, $rtl, $active, $translated, $default, $sort]) {
            Locale::query()->create([
                'locale_initials' => $code, 'english_name' => $english, 'native_name' => $native, 'script' => $script,
                'rtl' => $rtl, 'active' => $active, 'translated' => $translated, 'is_default' => $default, 'sort_order' => $sort,
            ]);
        }
    }

    protected function user(string $id = 'user-1'): GenericUser {
        return new GenericUser(['id' => $id]);
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Discovery/RootDiscoveryTest.php`:

```php
<?php

use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Discovery\RootDiscovery;

beforeEach(fn () => $this->useFixtureApp());

function rootMap(array $roots): array {
    return collect($roots)->mapWithKeys(fn (LangRoot $root) => [$root->namespace => $root->path])->all();
}

it('discovers the root lang folder and module namespaces, skipping excluded paths', function () {
    expect(rootMap(app(RootDiscovery::class)->roots()))->toBe([
        '*' => $this->fixture.'/lang',
        'identity' => $this->fixture.'/modules/Identity/Lang',
    ]);
});

it('filters namespaces by include, exclude and an explicit list', function () {
    config()->set('prosetta.namespaces.exclude', ['identity']);
    expect(array_keys(rootMap(app(RootDiscovery::class)->roots())))->toBe(['*']);

    config()->set('prosetta.namespaces.exclude', []);
    config()->set('prosetta.namespaces.include', ['nothing']);
    expect(array_keys(rootMap(app(RootDiscovery::class)->roots())))->toBe(['*']);

    config()->set('prosetta.namespaces.include', ['*']);
    expect(array_keys(rootMap(app(RootDiscovery::class)->roots(['identity']))))->toBe(['identity']);
});

it('lets config add or override namespace paths', function () {
    config()->set('prosetta.namespaces.discover', false);
    config()->set('prosetta.paths', ['identity' => $this->fixture.'/modules/Identity/Lang', 'ghost' => $this->fixture.'/missing']);

    expect(rootMap(app(RootDiscovery::class)->roots()))->toBe([
        '*' => $this->fixture.'/lang',
        'identity' => $this->fixture.'/modules/Identity/Lang',
    ]);
});
```

`tests/Feature/Discovery/LangReaderTest.php`:

```php
<?php

use LonelyLights\Prosetta\Discovery\LangReader;
use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Exceptions\LangFileException;

beforeEach(fn () => $this->useFixtureApp());

function rootOf(string $fixture): LangRoot {
    return new LangRoot('*', $fixture.'/lang');
}

it('lists PHP groups, nested folders and the JSON file', function () {
    expect(app(LangReader::class)->groups(rootOf($this->fixture), 'en'))->toBe([
        ['group' => 'admin/settings', 'format' => FileFormat::Php],
        ['group' => 'auth', 'format' => FileFormat::Php],
        ['group' => 'messages', 'format' => FileFormat::Php],
        ['group' => '*', 'format' => FileFormat::Json],
    ]);
});

it('lists module groups without JSON', function () {
    $root = new LangRoot('identity', $this->fixture.'/modules/Identity/Lang');

    expect(app(LangReader::class)->groups($root, 'en'))->toBe([['group' => 'onboarding', 'format' => FileFormat::Php]]);
});

it('flattens nested arrays in source order', function () {
    $root = new LangRoot('identity', $this->fixture.'/modules/Identity/Lang');

    expect(array_keys(app(LangReader::class)->read($root, 'en', 'onboarding', FileFormat::Php)))->toBe([
        'toast.accessCode.capReached', 'toast.accessCode.inUse', 'toast.accessCode.timedOut',
    ])
        ->and(app(LangReader::class)->read(rootOf($this->fixture), 'en', 'admin/settings', FileFormat::Php))->toBe([
            'title' => 'Settings', 'steps.0' => 'Open the menu', 'steps.1' => 'Choose a language',
        ]);
});

it('skips values that are not strings', function () {
    file_put_contents($this->fixture.'/lang/en/odd.php', "<?php return ['a' => 'A', 'n' => null, 'b' => true, 'i' => 3, 'e' => [], 'z' => 'Z'];");

    expect(app(LangReader::class)->read(rootOf($this->fixture), 'en', 'odd', FileFormat::Php))->toBe(['a' => 'A', 'z' => 'Z'])
        ->and(app(LangReader::class)->read(rootOf($this->fixture), 'en', 'messages', FileFormat::Php))->not->toHaveKey('limit');
});

it('keeps JSON keys whole', function () {
    expect(app(LangReader::class)->read(rootOf($this->fixture), 'en', '*', FileFormat::Json))->toBe([
        'Save changes' => 'Save changes', 'Version 2.0 is ready.' => 'Version 2.0 is ready.',
    ]);
});

it('returns nothing for a file that does not exist', function () {
    expect(app(LangReader::class)->read(rootOf($this->fixture), 'ar', 'auth', FileFormat::Php))->toBe([]);
});

it('builds the path a group lives at', function () {
    $reader = app(LangReader::class);

    expect($reader->path(rootOf($this->fixture), 'es', 'admin/settings', FileFormat::Php))->toBe($this->fixture.'/lang/es/admin/settings.php')
        ->and($reader->path(rootOf($this->fixture), 'es', '*', FileFormat::Json))->toBe($this->fixture.'/lang/es.json');
});

it('names the file when it cannot be read', function (string $contents) {
    file_put_contents($this->fixture.'/lang/en/broken.php', $contents);

    expect(fn () => app(LangReader::class)->read(rootOf($this->fixture), 'en', 'broken', FileFormat::Php))
        ->toThrow(LangFileException::class, 'broken.php');
})->with(['<?php return [', '<?php return "not an array";']);
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Discovery`
Expected: FAIL: classes in `LonelyLights\Prosetta\Discovery` not found.

- [ ] **Step 4: Implement**

`src/Exceptions/ProsettaException.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

use RuntimeException;

class ProsettaException extends RuntimeException {}
```

`src/Exceptions/LangFileException.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

use Throwable;

final class LangFileException extends ProsettaException {
    public static function unreadable(string $path, Throwable $previous): self {
        return new self("Could not read the lang file [$path]: {$previous->getMessage()}", 0, $previous);
    }

    public static function notAnArray(string $path): self {
        return new self("The lang file [$path] must return an array.");
    }
}
```

`src/Support/PathFilter.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Support;

/** Applies prosetta.exclude_paths: skipped when reading, refused when writing. */
final class PathFilter {
    public static function normalize(string $path): string {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');
    }

    public function excluded(string $path): bool {
        $path = self::normalize($path);

        foreach ((array) config('prosetta.exclude_paths', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $pattern = self::normalize(self::isAbsolute($pattern) ? $pattern : base_path($pattern));

            if ($this->matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, string $pattern): bool {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $pattern = strtolower($pattern);
        }

        return $path === $pattern || str_starts_with($path, $pattern.'/') || fnmatch($pattern, $path);
    }

    private static function isAbsolute(string $path): bool {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
```

`src/Discovery/LangRoot.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Discovery;

use LonelyLights\Prosetta\Support\KeyRef;

/** A folder holding {locale}/ subfolders of lang files, under one translation namespace. */
final readonly class LangRoot {
    public function __construct(
        public string $namespace,
        public string $path,
    ) {}

    public function isRoot(): bool {
        return $this->namespace === KeyRef::ROOT;
    }
}
```

`src/Discovery/RootDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Discovery;

use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\PathFilter;

/**
 * Finds every lang root: lang_path() as '*', plus each loadTranslationsFrom()
 * hint the translator knows (resolving the translator first so providers'
 * hints are registered), plus config('prosetta.paths') overrides.
 */
final class RootDiscovery {
    public function __construct(private readonly PathFilter $filter) {}

    /**
     * @param list<string>|null $only limit to these namespaces
     * @return list<LangRoot>
     */
    public function roots(?array $only = null): array {
        $candidates = [KeyRef::ROOT => lang_path()];

        if ((bool) config('prosetta.namespaces.discover', true)) {
            $loader = app('translator')->getLoader();

            if (method_exists($loader, 'namespaces')) {
                foreach ($loader->namespaces() as $namespace => $path) {
                    $candidates[(string) $namespace] = (string) $path;
                }
            }
        }

        foreach ((array) config('prosetta.paths', []) as $namespace => $path) {
            $candidates[(string) $namespace] = (string) $path;
        }

        $include = (array) config('prosetta.namespaces.include', ['*']);
        $exclude = (array) config('prosetta.namespaces.exclude', []);
        $roots = [];

        foreach ($candidates as $namespace => $path) {
            $namespace = (string) $namespace;

            if ($only !== null && ! in_array($namespace, $only, true)) {
                continue;
            }

            if (in_array($namespace, $exclude, true)) {
                continue;
            }

            if ($namespace !== KeyRef::ROOT && ! in_array('*', $include, true) && ! in_array($namespace, $include, true)) {
                continue;
            }

            $normalized = PathFilter::normalize($path);

            if (! is_dir($normalized) || $this->filter->excluded($normalized)) {
                continue;
            }

            $roots[] = new LangRoot($namespace, $normalized);
        }

        return $roots;
    }
}
```

`src/Discovery/LangReader.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Discovery;

use Illuminate\Filesystem\Filesystem;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Exceptions\LangFileException;
use LonelyLights\Prosetta\Support\KeyRef;
use Throwable;

/** Reads lang files the way Laravel loads them, flattened to dot keys in source order. */
final class LangReader {
    public function __construct(private readonly Filesystem $files) {}

    /** @return list<array{group: string, format: FileFormat}> */
    public function groups(LangRoot $root, string $locale): array {
        $groups = [];
        $directory = $root->path.'/'.$locale;

        if (is_dir($directory)) {
            foreach ($this->files->allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $groups[] = ['group' => substr($relative, 0, -4), 'format' => FileFormat::Php];
            }
        }

        usort($groups, fn (array $a, array $b) => strcmp($a['group'], $b['group']));

        if ($root->isRoot() && is_file($root->path.'/'.$locale.'.json')) {
            $groups[] = ['group' => KeyRef::JSON_GROUP, 'format' => FileFormat::Json];
        }

        return $groups;
    }

    public function path(LangRoot $root, string $locale, string $group, FileFormat $format): string {
        return $format === FileFormat::Json
            ? $root->path.'/'.$locale.'.json'
            : $root->path.'/'.$locale.'/'.$group.'.php';
    }

    /** @return array<string, string> */
    public function read(LangRoot $root, string $locale, string $group, FileFormat $format): array {
        $path = $this->path($root, $locale, $group, $format);

        if (! is_file($path)) {
            return [];
        }

        return $format === FileFormat::Json ? $this->readJson($path) : $this->flatten($this->readPhp($path));
    }

    /** @return array<array-key, mixed> */
    private function readPhp(string $path): array {
        try {
            $data = $this->files->getRequire($path);
        } catch (Throwable $e) {
            throw LangFileException::unreadable($path, $e);
        }

        if (! is_array($data)) {
            throw LangFileException::notAnArray($path);
        }

        return $data;
    }

    /** @return array<string, string> */
    private function readJson(string $path): array {
        try {
            $data = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw LangFileException::unreadable($path, $e);
        }

        if (! is_array($data)) {
            throw LangFileException::notAnArray($path);
        }

        $values = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $values[(string) $key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array {
        $values = [];

        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $values += $this->flatten($value, $full);
            } elseif (is_string($value)) {
                $values[$full] = $value;
            }
        }

        return $values;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Discover lang roots and read lang files with an Undaunted-shaped fixture" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 8: Sync

**Files:**
- Create: `src/Sync/Syncer.php`, `src/Sync/SyncReport.php`, `src/Events/{KeyAdded,KeyChanged,KeyObsoleted,SyncCompleted}.php`, `tests/Feature/Sync/SyncerTest.php`

**Interfaces:**
- Consumes: `RootDiscovery::roots()`, `LangReader::groups()/read()`, `LocaleSource`, `PlaceholderGuard::check()`, `Issue::store()/anyBlocking()`, `Placeholders::unique()`, `Fingerprint::of()`, models via `Settings::model()`.
- Produces:
  - `Syncer::sync(?array $namespaces = null, bool $quiet = false): SyncReport`, which runs in one transaction and dispatches events after commit unless `$quiet`.
  - `SyncReport` with public `array $added`, `$changed`, `$restored`, `$obsoleted` (lists of ref strings), `int $imported`, `array $handEdits` (`"{locale} {ref}"`), `?int $outstanding` (set by Task 15), `hasChanges(): bool`, `toArray(): array`.
  - Events: `KeyAdded(TranslationKey $key)`, `KeyChanged(TranslationKey $key, string $previousValue)`, `KeyObsoleted(TranslationKey $key)`, `SyncCompleted(SyncReport $report)`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Sync/SyncerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\KeyAdded;
use LonelyLights\Prosetta\Events\KeyChanged;
use LonelyLights\Prosetta\Events\SyncCompleted;
use LonelyLights\Prosetta\Exceptions\LangFileException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
});

function translationFor(string $ref, string $locale): ?Translation {
    return Translation::query()->where('locale', $locale)->get()->first(fn (Translation $t) => $t->key->ref()->toString() === $ref);
}

it('reads the source locale into files and keys', function () {
    $report = app(Syncer::class)->sync();

    expect(TranslationFile::query()->count())->toBe(5)
        ->and(TranslationKey::query()->count())->toBe(13)
        ->and($report->added)->toContain('identity::onboarding.toast.accessCode.capReached', 'json:Version 2.0 is ready.', 'admin/settings.steps.1')
        ->and($report->added)->not->toContain('messages.limit')
        ->and(TranslationKey::query()->withKey('toast.accessCode.capReached')->first()->placeholders)->toBe([':minutes']);
});

it('imports existing target values as approved work', function () {
    $report = app(Syncer::class)->sync();
    $inUse = translationFor('identity::onboarding.toast.accessCode.inUse', 'es');

    expect($report->imported)->toBe(4)
        ->and($inUse->status)->toBe(TranslationStatus::Approved)
        ->and($inUse->origin)->toBe(TranslationOrigin::Imported)
        ->and($inUse->approved_value)->toBe($inUse->value)
        ->and($inUse->approved_source_hash)->toBe($inUse->key->source_hash)
        ->and($inUse->exported_hash)->toBe(Fingerprint::of($inUse->value))
        ->and(translationFor('messages.welcome', 'en_GB'))->not->toBeNull()
        ->and(translationFor('auth.failed', 'fr'))->toBeNull();
});

it('changes nothing when run again', function () {
    app(Syncer::class)->sync();
    $report = app(Syncer::class)->sync();

    expect($report->hasChanges())->toBeFalse()
        ->and($report->imported)->toBe(0)
        ->and($report->handEdits)->toBe([]);
});

it('marks translations stale when the English changes', function () {
    app(Syncer::class)->sync();
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Those details do not match.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");

    $report = app(Syncer::class)->sync();
    $failed = translationFor('auth.failed', 'es');

    expect($report->changed)->toBe(['auth.failed'])
        ->and(WorkState::isStale($failed->key, $failed))->toBeTrue()
        ->and($failed->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('obsoletes removed keys and files, and restores keys that come back', function () {
    app(Syncer::class)->sync();
    $original = file_get_contents($this->fixture.'/lang/en/auth.php');
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'These credentials do not match our records.'];");
    unlink($this->fixture.'/lang/en/admin/settings.php');

    $report = app(Syncer::class)->sync();

    expect($report->obsoleted)->toEqualCanonicalizing(['auth.throttle', 'admin/settings.title', 'admin/settings.steps.0', 'admin/settings.steps.1']);

    file_put_contents($this->fixture.'/lang/en/auth.php', $original);

    expect(app(Syncer::class)->sync()->restored)->toBe(['auth.throttle'])
        ->and(TranslationKey::query()->withKey('throttle')->first()->obsolete_at)->toBeNull();
});

it('imports a hand edit to a target file for review without replacing the approved value', function () {
    app(Syncer::class)->sync();
    file_put_contents($this->fixture.'/lang/es/auth.php', "<?php return ['failed' => 'Credenciales incorrectas.'];");

    $report = app(Syncer::class)->sync();
    $failed = translationFor('auth.failed', 'es');
    $review = $failed->reviews()->latest('id')->first();

    expect($report->handEdits)->toBe(['es auth.failed'])
        ->and($failed->value)->toBe('Credenciales incorrectas.')
        ->and($failed->status)->toBe(TranslationStatus::NeedsReview)
        ->and($failed->origin)->toBe(TranslationOrigin::Manual)
        ->and($failed->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and($review->action)->toBe(ReviewAction::Imported)
        ->and($review->previous_value)->toBe('Estas credenciales no coinciden con nuestros registros.')
        ->and(app(Syncer::class)->sync()->handEdits)->toBe([]);
});

it('imports a broken target value as needing review, never as approved', function () {
    file_put_contents($this->fixture.'/lang/es/messages.php', "<?php return ['welcome' => '¡Bienvenido, :nombre!'];");

    app(Syncer::class)->sync();
    $welcome = translationFor('messages.welcome', 'es');

    expect($welcome->status)->toBe(TranslationStatus::NeedsReview)
        ->and($welcome->approved_value)->toBeNull()
        ->and($welcome->hasBlockingIssues())->toBeTrue();
});

it('a broken source file aborts the sync without obsoleting anything', function () {
    app(Syncer::class)->sync();
    file_put_contents($this->fixture.'/lang/en/auth.php', '<?php return [');

    expect(fn () => app(Syncer::class)->sync())->toThrow(LangFileException::class, 'auth.php')
        ->and(TranslationKey::query()->whereNotNull('obsolete_at')->count())->toBe(0);
});

it('syncs only the namespaces asked for', function () {
    app(Syncer::class)->sync(['identity']);

    expect(TranslationFile::query()->pluck('namespace')->unique()->values()->all())->toBe(['identity']);
});

it('dispatches events after the transaction, unless quiet', function () {
    Event::fake([KeyAdded::class, KeyChanged::class, SyncCompleted::class]);

    app(Syncer::class)->sync(quiet: true);
    Event::assertNothingDispatched();

    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Changed.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    Event::assertDispatched(KeyChanged::class, fn (KeyChanged $event) => $event->previousValue === 'These credentials do not match our records.');
    Event::assertDispatched(SyncCompleted::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Sync/SyncerTest.php`
Expected: FAIL: class `LonelyLights\Prosetta\Sync\Syncer` not found.

- [ ] **Step 3: Implement**

`src/Events/KeyAdded.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\TranslationKey;

final class KeyAdded {
    public function __construct(public readonly TranslationKey $key) {}
}
```

`src/Events/KeyChanged.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\TranslationKey;

final class KeyChanged {
    public function __construct(
        public readonly TranslationKey $key,
        public readonly string $previousValue,
    ) {}
}
```

`src/Events/KeyObsoleted.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\TranslationKey;

final class KeyObsoleted {
    public function __construct(public readonly TranslationKey $key) {}
}
```

`src/Events/SyncCompleted.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Sync\SyncReport;

final class SyncCompleted {
    public function __construct(public readonly SyncReport $report) {}
}
```

`src/Sync/SyncReport.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Sync;

final class SyncReport {
    /** @var list<string> */
    public array $added = [];

    /** @var list<string> */
    public array $changed = [];

    /** @var list<string> */
    public array $restored = [];

    /** @var list<string> */
    public array $obsoleted = [];

    public int $imported = 0;

    /** @var list<string> "{locale} {ref}" */
    public array $handEdits = [];

    /** Missing + stale + awaiting review + broken, across targets. Set only in check mode. */
    public ?int $outstanding = null;

    public function hasChanges(): bool {
        return $this->added !== [] || $this->changed !== [] || $this->restored !== [] || $this->obsoleted !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'added' => $this->added, 'changed' => $this->changed, 'restored' => $this->restored,
            'obsoleted' => $this->obsoleted, 'imported' => $this->imported, 'hand_edits' => $this->handEdits,
            'outstanding' => $this->outstanding,
        ];
    }
}
```

`src/Sync/Syncer.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Sync;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Discovery\LangReader;
use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Discovery\RootDiscovery;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\KeyAdded;
use LonelyLights\Prosetta\Events\KeyChanged;
use LonelyLights\Prosetta\Events\KeyObsoleted;
use LonelyLights\Prosetta\Events\SyncCompleted;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\Placeholders;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Reads the source locale's files into keys, then imports what the target
 * locales' files already say. Never writes a file. One transaction: a
 * broken source file aborts everything, so nothing is wrongly obsoleted.
 */
final class Syncer {
    public function __construct(
        private readonly RootDiscovery $discovery,
        private readonly LangReader $reader,
        private readonly LocaleSource $locales,
        private readonly PlaceholderGuard $guard,
        private readonly Dispatcher $events,
    ) {}

    /** @param list<string>|null $namespaces */
    public function sync(?array $namespaces = null, bool $quiet = false): SyncReport {
        $report = new SyncReport;
        $pending = [];

        DB::transaction(function () use ($namespaces, $report, &$pending): void {
            $source = $this->locales->source();
            $targets = array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets());

            foreach ($this->discovery->roots($namespaces) as $root) {
                $this->syncRoot($root, $source, $targets, $report, $pending);
            }
        });

        if (! $quiet) {
            foreach ($pending as $event) {
                $this->events->dispatch($event);
            }

            $this->events->dispatch(new SyncCompleted($report));
        }

        return $report;
    }

    /**
     * @param list<string> $targets
     * @param list<object> $pending
     */
    private function syncRoot(LangRoot $root, string $source, array $targets, SyncReport $report, array &$pending): void {
        $fileModel = Settings::model('file');
        $seen = [];

        foreach ($this->reader->groups($root, $source) as ['group' => $group, 'format' => $format]) {
            $values = $this->reader->read($root, $source, $group, $format);
            /** @var TranslationFile $file */
            $file = $fileModel::query()->firstOrCreate(['namespace' => $root->namespace, 'group' => $group], ['format' => $format]);
            $seen[] = $file->getKey();
            $keys = $this->syncKeys($file, $values, $report, $pending);

            foreach ($targets as $locale) {
                $this->importTarget($root, $file, $keys, $locale, $report);
            }
        }

        # A Group Whose Source File Disappeared: Every Key in It Becomes Obsolete
        $fileModel::query()->where('namespace', $root->namespace)->whereKeyNot($seen)->get()
            ->each(function (TranslationFile $file) use ($report, &$pending): void {
                $this->syncKeys($file, [], $report, $pending);
            });
    }

    /**
     * @param array<string, string> $values
     * @param list<object> $pending
     * @return Collection<string, TranslationKey>
     */
    private function syncKeys(TranslationFile $file, array $values, SyncReport $report, array &$pending): Collection {
        $keyModel = Settings::model('key');
        $existing = $keyModel::query()->where('file_id', $file->getKey())->get()->keyBy('key');
        $current = new Collection;

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $hash = Fingerprint::of($value);
            /** @var TranslationKey|null $model */
            $model = $existing->get($key);

            if ($model === null) {
                $model = $keyModel::query()->create([
                    'file_id' => $file->getKey(), 'key' => $key, 'source_value' => $value,
                    'source_hash' => $hash, 'placeholders' => Placeholders::unique($value),
                ]);
                $model->setRelation('file', $file);
                $report->added[] = $model->ref()->toString();
                $pending[] = new KeyAdded($model);
            } elseif ($model->source_hash !== $hash) {
                $previous = $model->source_value;
                $model->update(['source_value' => $value, 'source_hash' => $hash, 'placeholders' => Placeholders::unique($value), 'obsolete_at' => null]);
                $model->setRelation('file', $file);
                $report->changed[] = $model->ref()->toString();
                $pending[] = new KeyChanged($model, $previous);
            } elseif ($model->obsolete_at !== null) {
                $model->update(['obsolete_at' => null]);
                $model->setRelation('file', $file);
                $report->restored[] = $model->ref()->toString();
            }

            $current->put($key, $model);
        }

        foreach ($existing as $key => $model) {
            if (! array_key_exists($key, $values) && $model->obsolete_at === null) {
                $model->update(['obsolete_at' => now()]);
                $model->setRelation('file', $file);
                $report->obsoleted[] = $model->ref()->toString();
                $pending[] = new KeyObsoleted($model);
            }
        }

        return $current;
    }

    /** @param Collection<string, TranslationKey> $keys */
    private function importTarget(LangRoot $root, TranslationFile $file, Collection $keys, string $locale, SyncReport $report): void {
        $values = $this->reader->read($root, $locale, $file->group, $file->format);

        if ($values === [] || $keys->isEmpty()) {
            return;
        }

        $translationModel = Settings::model('translation');
        $existing = $translationModel::query()->where('locale', $locale)
            ->whereIn('key_id', $keys->map(fn (TranslationKey $key) => $key->getKey())->values()->all())
            ->get()->keyBy('key_id');

        foreach ($keys as $key => $model) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];
            $fileHash = Fingerprint::of($value);
            $issues = Issue::store($this->guard->check($model->source_value, $value, $locale));
            $translation = $existing->get($model->getKey());

            if ($translation === null) {
                $clean = ! Issue::anyBlocking($issues);
                $translationModel::query()->create([
                    'key_id' => $model->getKey(), 'locale' => $locale,
                    'value' => $value, 'source_hash' => $model->source_hash,
                    'approved_value' => $clean ? $value : null,
                    'approved_source_hash' => $clean ? $model->source_hash : null,
                    'status' => $clean ? TranslationStatus::Approved : TranslationStatus::NeedsReview,
                    'origin' => TranslationOrigin::Imported,
                    'issues' => $issues, 'exported_hash' => $fileHash,
                ]);
                $report->imported++;

                continue;
            }

            if ($translation->exported_hash === $fileHash) {
                continue;
            }

            if ($value === $translation->value || $value === $translation->approved_value) {
                $translation->update(['exported_hash' => $fileHash]);

                continue;
            }

            # Someone Edited Prosetta's Output by Hand: Keep It as a Candidate, Never as Approved
            $previous = $translation->value;
            $translation->update([
                'value' => $value, 'source_hash' => $model->source_hash,
                'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Manual,
                'issues' => $issues, 'exported_hash' => $fileHash,
            ]);
            $translation->reviews()->create([
                'reviewer_id' => null, 'action' => ReviewAction::Imported,
                'previous_value' => $previous, 'new_value' => $value, 'notes' => 'Edited by hand in the lang file.',
            ]);
            $report->handEdits[] = $locale.' '.$model->ref()->toString();
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Sync source keys and import target files without overwriting reviewed work" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 9: KeyFinder and rename

**Files:**
- Create: `src/Queries/KeyFinder.php`, `src/Sync/Renamer.php`, `tests/Feature/Sync/RenamerTest.php`

**Interfaces:**
- Consumes: `KeyRef::parse()`, `TranslationKey::withKey()`, `Syncer::sync()`.
- Produces: `KeyFinder::find(KeyRef|string $ref): ?TranslationKey` (with `file` and `translations` loaded); `Renamer::rename(string $from, string $to): TranslationKey` (throws `ProsettaException`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Sync/RenamerTest.php`:

```php
<?php

use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Sync\Renamer;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function renameInUseInEnglish(string $fixture): void {
    $path = $fixture.'/modules/Identity/Lang/en/onboarding.php';
    file_put_contents($path, str_replace("'inUse' =>", "'inUseElsewhere' =>", file_get_contents($path)));
}

it('finds a key by reference', function () {
    $key = app(KeyFinder::class)->find('identity::onboarding.toast.accessCode.inUse');

    expect($key?->key)->toBe('toast.accessCode.inUse')
        ->and($key->translations)->toHaveCount(1)
        ->and(app(KeyFinder::class)->find('json:Save changes')?->source_value)->toBe('Save changes')
        ->and(app(KeyFinder::class)->find('auth.nope'))->toBeNull();
});

it('moves translations and history to the renamed key', function () {
    renameInUseInEnglish($this->fixture);
    app(Syncer::class)->sync();

    $key = app(Renamer::class)->rename('identity::onboarding.toast.accessCode.inUse', 'identity::onboarding.toast.accessCode.inUseElsewhere');
    $spanish = $key->translations->firstWhere('locale', 'es');

    expect($spanish->approved_value)->toStartWith('Este código de acceso')
        ->and(WorkState::isStale($key, $spanish))->toBeFalse()
        ->and(TranslationKey::query()->withKey('toast.accessCode.inUse')->exists())->toBeFalse();
});

it('refuses to rename onto a key that already has translations', function () {
    expect(fn () => app(Renamer::class)->rename('identity::onboarding.toast.accessCode.inUse', 'auth.failed'))
        ->toThrow(ProsettaException::class, 'already has translations');
});

it('refuses references that do not exist yet', function () {
    expect(fn () => app(Renamer::class)->rename('identity::onboarding.toast.accessCode.inUse', 'identity::onboarding.toast.nope'))
        ->toThrow(ProsettaException::class, 'Rename it in the source file');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Sync/RenamerTest.php`
Expected: FAIL: class `LonelyLights\Prosetta\Queries\KeyFinder` not found.

- [ ] **Step 3: Implement**

`src/Queries/KeyFinder.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\Settings;

final class KeyFinder {
    public function find(KeyRef|string $ref): ?TranslationKey {
        $ref = is_string($ref) ? KeyRef::parse($ref) : $ref;
        $fileModel = Settings::model('file');
        $file = $fileModel::query()->where('namespace', $ref->namespace)->where('group', $ref->group)->first();

        if ($file === null) {
            return null;
        }

        /** @var TranslationKey|null $key */
        $key = $file->keys()->withKey($ref->key)->with('translations')->first();
        $key?->setRelation('file', $file);

        return $key;
    }
}
```

`src/Sync/Renamer.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Sync;

use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;

/**
 * Carries translations across a rename. Rename the key in the source file,
 * run sync (old key obsolete, new key added), then rename: the translations
 * and their review history move to the new key and the old key is deleted.
 */
final class Renamer {
    public function __construct(private readonly KeyFinder $finder) {}

    public function rename(string $from, string $to): TranslationKey {
        $old = $this->finder->find($from) ?? throw new ProsettaException("No key [$from]. Run prosetta:sync first if you only just renamed it.");
        $new = $this->finder->find($to) ?? throw new ProsettaException("No key [$to]. Rename it in the source file and run prosetta:sync first.");

        if ($old->is($new)) {
            throw new ProsettaException('Both references name the same key.');
        }

        if ($new->translations()->exists()) {
            throw new ProsettaException("[$to] already has translations; refusing to overwrite them.");
        }

        DB::transaction(function () use ($old, $new): void {
            $old->translations()->update(['key_id' => $new->getKey()]);
            $old->delete();
        });

        return $new->fresh(['file', 'translations']);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add KeyFinder and a rename that carries translations across" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 10: Per-language authorization

**Files:**
- Create: `src/Enums/Ability.php`, `src/Auth/Authorizer.php`, `tests/Feature/Auth/AuthorizerTest.php`
- Modify: `src/ProsettaServiceProvider.php`

**Interfaces:**
- Produces:
  - `enum Ability: string { Translate = 'translate'; Review = 'review'; Manage = 'manage'; gate(): string }` (`gate()` returns `prosetta.{value}`).
  - `Authorizer` (singleton) with `using(Closure $callback): void`, `allows(?Authenticatable $user, Ability $ability, ?string $locale = null): bool`, `authorize(...)`, which throws `Illuminate\Auth\Access\AuthorizationException`.
  - Gates `prosetta.translate`, `prosetta.review`, `prosetta.manage` (argument `?string $locale`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Auth/AuthorizerTest.php`:

```php
<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;

it('denies outside local when no hook is registered', function () {
    expect(app(Authorizer::class)->allows($this->user(), Ability::Review, 'es'))->toBeFalse();
});

it('allows everything locally when no hook is registered', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(app(Authorizer::class)->allows($this->user(), Ability::Manage))->toBeTrue();
});

it('always allows the system (no user)', function () {
    expect(app(Authorizer::class)->allows(null, Ability::Manage))->toBeTrue();
});

it('asks the host hook with the ability and the locale', function () {
    app(Authorizer::class)->using(fn (Authenticatable $user, Ability $ability, ?string $locale) => $ability === Ability::Review && $locale === 'ar');

    expect(app(Authorizer::class)->allows($this->user(), Ability::Review, 'ar'))->toBeTrue()
        ->and(app(Authorizer::class)->allows($this->user(), Ability::Review, 'es'))->toBeFalse()
        ->and(app(Authorizer::class)->allows($this->user(), Ability::Manage))->toBeFalse();
});

it('lets a reviewer translate the locales they review', function () {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability === Ability::Review && $locale === 'ar');

    expect(app(Authorizer::class)->allows($this->user(), Ability::Translate, 'ar'))->toBeTrue()
        ->and(app(Authorizer::class)->allows($this->user(), Ability::Translate, 'es'))->toBeFalse();
});

it('registers Laravel gates that take the locale', function () {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $locale === 'es');

    expect(Gate::forUser($this->user())->allows('prosetta.review', 'es'))->toBeTrue()
        ->and(Gate::forUser($this->user())->allows('prosetta.review', 'ar'))->toBeFalse();
});

it('throws a standard authorization exception', function () {
    expect(fn () => app(Authorizer::class)->authorize($this->user(), Ability::Review, 'es'))
        ->toThrow(AuthorizationException::class, 'review es');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Auth/AuthorizerTest.php`
Expected: FAIL: class `LonelyLights\Prosetta\Auth\Authorizer` not found.

- [ ] **Step 3: Implement**

`src/Enums/Ability.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Enums;

enum Ability: string {
    /** Edit candidates and request AI drafts for a locale. */
    case Translate = 'translate';
    /** Approve, reject or edit-and-approve for a locale; implies Translate. */
    case Review = 'review';
    /** Sync, export and rename; not tied to a locale. */
    case Manage = 'manage';

    public function gate(): string {
        return 'prosetta.'.$this->value;
    }
}
```

`src/Auth/Authorizer.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Auth;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use LonelyLights\Prosetta\Enums\Ability;

/**
 * The one place a host decides who may do what, per locale:
 * Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale): bool => ...).
 * With no hook, only the local environment is allowed. A null user is the
 * system (console, queue) and is always allowed.
 */
final class Authorizer {
    private ?Closure $callback = null;

    public function using(Closure $callback): void {
        $this->callback = $callback;
    }

    public function allows(?Authenticatable $user, Ability $ability, ?string $locale = null): bool {
        if ($user === null) {
            return true;
        }

        if ($this->callback === null) {
            return app()->environment('local');
        }

        if (($this->callback)($user, $ability, $locale) === true) {
            return true;
        }

        return $ability === Ability::Translate && ($this->callback)($user, Ability::Review, $locale) === true;
    }

    public function authorize(?Authenticatable $user, Ability $ability, ?string $locale = null): void {
        if (! $this->allows($user, $ability, $locale)) {
            throw new AuthorizationException(sprintf('Not allowed to %s%s.', $ability->value, $locale === null ? '' : " $locale"));
        }
    }
}
```

Replace `src/ProsettaServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');

        $this->app->singleton(Authorizer::class);
        $this->app->bind(LocaleSource::class, fn (Application $app) => $app->make((string) config('prosetta.locales.source', DatabaseLocaleSource::class)));
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');
        }

        foreach (Ability::cases() as $ability) {
            Gate::define($ability->gate(), fn (Authenticatable $user, ?string $locale = null): bool => $this->app->make(Authorizer::class)->allows($user, $ability, $locale));
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add per-language authorization through one host hook and gates" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 11: Review service and review queue

**Files:**
- Create: `src/Review/ReviewService.php`, `src/Review/ApproveReport.php`, `src/Review/ReviewQueue.php`, `src/Review/ReviewItem.php`, `src/Events/{TranslationSubmitted,TranslationApproved,TranslationRejected}.php`, `tests/Feature/Review/ReviewServiceTest.php`, `tests/Feature/Review/ReviewQueueTest.php`

**Interfaces:**
- Consumes: `Authorizer::authorize()`, `PlaceholderGuard`, `Issue::store()`, `WorkState`, `KeyFinder` (in tests), models.
- Produces:
  - `ReviewService::edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation`
  - `->approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null): ApproveReport`
  - `->reject(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation`
  - `->approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null): ApproveReport`
  - `ApproveReport` with `array $approved` (ids) and `array $skipped` (id → reason: `empty`, `rejected`, `issues`, `already_approved`, `self_approval`).
  - `ReviewQueue::forLocale(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator` of `ReviewItem`. Filters: `status` (string|list), `stale` bool, `namespace`, `group`, `origin`, `issues` bool, `search`.
  - `ReviewItem` readonly with `translationId`, `keyRef`, `locale`, `source`, `candidate`, `approved`, `status`, `origin`, `stale`, `issues`, `context`, `maxLength`, `aiModel`, `aiProvider`; `toArray()`.
  - Events: `TranslationSubmitted(Translation $translation, ?string $by)`, `TranslationApproved(...)`, `TranslationRejected(...)`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Review/ReviewServiceTest.php`:

```php
<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Events\TranslationRejected;
use LonelyLights\Prosetta\Events\TranslationSubmitted;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $locale === 'es' || $ability === Ability::Manage);
});

function draftFor(string $ref, string $locale, string $value): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->create([
        'key_id' => $key->id, 'locale' => $locale, 'value' => $value, 'source_hash' => $key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai,
    ]);
}

it('edits a candidate into needs-review and records who did it', function () {
    Event::fake([TranslationSubmitted::class]);
    $draft = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'Borrador');

    $edited = app(ReviewService::class)->edit($draft->id, 'El registro tiene un límite de :minutes minutos.', $this->user('ana'), 'Tightened');
    $review = $edited->reviews()->latest('id')->first();

    expect($edited->status)->toBe(TranslationStatus::NeedsReview)
        ->and($edited->origin)->toBe(TranslationOrigin::Manual)
        ->and($edited->issues)->toBeNull()
        ->and($review->action)->toBe(ReviewAction::Edited)
        ->and($review->reviewer_id)->toBe('ana')
        ->and($review->previous_value)->toBe('Borrador');
    Event::assertDispatched(TranslationSubmitted::class);
});

it('approves a clean candidate into the live value', function () {
    Event::fake([TranslationApproved::class]);
    $draft = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'El registro tiene un límite de :minutes minutos.');

    $report = app(ReviewService::class)->approve($draft->id, $this->user('ana'));
    $draft->refresh();

    expect($report->approved)->toBe([$draft->id])
        ->and($draft->approved_value)->toBe('El registro tiene un límite de :minutes minutos.')
        ->and($draft->approved_source_hash)->toBe($draft->source_hash)
        ->and($draft->status)->toBe(TranslationStatus::Approved)
        ->and($draft->reviewed_by)->toBe('ana');
    Event::assertDispatched(TranslationApproved::class);
});

it('refuses to approve candidates with blocking issues, rejected ones and empty ones', function () {
    $broken = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'Sin marcador.');
    $broken->update(['issues' => [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'x']]]);
    $rejected = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');
    $rejected->update(['status' => TranslationStatus::Rejected]);

    $report = app(ReviewService::class)->approve([$broken->id, $rejected->id], $this->user());

    expect($report->approved)->toBe([])
        ->and($report->skipped)->toBe([$broken->id => 'issues', $rejected->id => 'rejected']);
});

it('rejects a candidate without touching the live value', function () {
    Event::fake([TranslationRejected::class]);
    $failed = app(KeyFinder::class)->find('auth.failed')->translations->firstWhere('locale', 'es');
    $failed->update(['value' => 'Mal', 'status' => TranslationStatus::NeedsReview]);

    $rejected = app(ReviewService::class)->reject($failed->id, $this->user(), 'Too curt');

    expect($rejected->status)->toBe(TranslationStatus::Rejected)
        ->and($rejected->approved_value)->toBe('Estas credenciales no coinciden con nuestros registros.');
    Event::assertDispatched(TranslationRejected::class);
});

it('edits and approves in one step', function () {
    $draft = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');

    $done = app(ReviewService::class)->edit($draft->id, 'Tu registro estuvo en pausa.', $this->user(), approve: true);

    expect($done->status)->toBe(TranslationStatus::Approved)
        ->and($done->approved_value)->toBe('Tu registro estuvo en pausa.');
});

it('can require a second person', function () {
    config()->set('prosetta.review.allow_self_approval', false);
    $draft = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');
    app(ReviewService::class)->edit($draft->id, 'Tu registro estuvo en pausa.', $this->user('ana'));

    expect(app(ReviewService::class)->approve($draft->id, $this->user('ana'))->skipped)->toBe([$draft->id => 'self_approval'])
        ->and(app(ReviewService::class)->approve($draft->id, $this->user('ben'))->approved)->toBe([$draft->id]);
});

it('checks the locale on every action', function () {
    $arabic = draftFor('identity::onboarding.toast.accessCode.timedOut', 'ar', 'x');

    expect(fn () => app(ReviewService::class)->approve($arabic->id, $this->user()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReviewService::class)->edit($arabic->id, 'y', $this->user()))->toThrow(AuthorizationException::class);
});

it('bulk-approves every clean current candidate for a locale', function () {
    $good = draftFor('identity::onboarding.toast.accessCode.capReached', 'es', 'Límite de :minutes minutos.');
    $bad = draftFor('identity::onboarding.toast.accessCode.timedOut', 'es', 'x');
    $bad->update(['issues' => [['code' => 'empty_value', 'severity' => 'error', 'message' => 'x']]]);

    $report = app(ReviewService::class)->approveClean('es', 'identity', by: $this->user());

    expect($report->approved)->toBe([$good->id])
        ->and($report->skipped)->toBe([$bad->id => 'issues']);
});
```

`tests/Feature/Review/ReviewQueueTest.php`:

```php
<?php

use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewItem;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();

    $key = app(KeyFinder::class)->find('identity::onboarding.toast.accessCode.capReached');
    Translation::query()->create([
        'key_id' => $key->id, 'locale' => 'es', 'value' => 'Límite de :minutes minutos.', 'source_hash' => $key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai, 'ai_model' => 'gpt-test', 'ai_provider' => 'openai',
    ]);
});

it('lists drafts and needs-review items for one locale', function () {
    $page = app(ReviewQueue::class)->forLocale('es');
    $item = $page->items()[0];

    expect($page->total())->toBe(1)
        ->and($item)->toBeInstanceOf(ReviewItem::class)
        ->and($item->keyRef)->toBe('identity::onboarding.toast.accessCode.capReached')
        ->and($item->source)->toStartWith('Registration has a :minutes-minute limit')
        ->and($item->candidate)->toBe('Límite de :minutes minutos.')
        ->and($item->status)->toBe('draft')
        ->and($item->aiModel)->toBe('gpt-test')
        ->and($item->stale)->toBeFalse()
        ->and(app(ReviewQueue::class)->forLocale('ar')->total())->toBe(0);
});

it('includes approved-but-stale items when asked', function () {
    $path = $this->fixture.'/lang/en/auth.php';
    file_put_contents($path, str_replace('do not match our records', 'are wrong', file_get_contents($path)));
    app(Syncer::class)->sync();

    $page = app(ReviewQueue::class)->forLocale('es', ['stale' => true]);

    expect(collect($page->items())->pluck('keyRef')->all())->toContain('auth.failed')
        ->and(collect($page->items())->firstWhere('keyRef', 'auth.failed')->stale)->toBeTrue();
});

it('filters by namespace, origin and search', function () {
    expect(app(ReviewQueue::class)->forLocale('es', ['namespace' => '*'])->total())->toBe(0)
        ->and(app(ReviewQueue::class)->forLocale('es', ['origin' => 'ai'])->total())->toBe(1)
        ->and(app(ReviewQueue::class)->forLocale('es', ['search' => 'Límite'])->total())->toBe(1)
        ->and(app(ReviewQueue::class)->forLocale('es', ['search' => 'nothing like this'])->total())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Review`
Expected: FAIL: classes in `LonelyLights\Prosetta\Review` not found.

- [ ] **Step 3: Implement**

`src/Events/TranslationSubmitted.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\Translation;

final class TranslationSubmitted {
    public function __construct(
        public readonly Translation $translation,
        public readonly ?string $by,
    ) {}
}
```

`src/Events/TranslationApproved.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\Translation;

final class TranslationApproved {
    public function __construct(
        public readonly Translation $translation,
        public readonly ?string $by,
    ) {}
}
```

`src/Events/TranslationRejected.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\Translation;

final class TranslationRejected {
    public function __construct(
        public readonly Translation $translation,
        public readonly ?string $by,
    ) {}
}
```

`src/Review/ApproveReport.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

final class ApproveReport {
    /** @var list<int> */
    public array $approved = [];

    /** @var array<int, string> translation id => reason */
    public array $skipped = [];
}
```

`src/Review/ReviewService.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Events\TranslationRejected;
use LonelyLights\Prosetta\Events\TranslationSubmitted;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Support\Settings;

/** Every human action on a translation. Each one checks the locale, leaves a review row and fires an event. */
final class ReviewService {
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly PlaceholderGuard $guard,
        private readonly Dispatcher $events,
    ) {}

    public function edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation {
        $translation = $this->load($translationId);
        $this->authorizer->authorize($by, $approve ? Ability::Review : Ability::Translate, $translation->locale);
        $key = $translation->key;
        $previous = $translation->value;

        DB::transaction(function () use ($translation, $key, $value, $by, $notes, $previous): void {
            $translation->update([
                'value' => $value, 'source_hash' => $key->source_hash,
                'status' => TranslationStatus::NeedsReview, 'origin' => TranslationOrigin::Manual,
                'issues' => Issue::store($this->guard->check($key->source_value, $value, $translation->locale)),
            ]);
            $translation->reviews()->create([
                'reviewer_id' => $this->id($by), 'action' => ReviewAction::Edited,
                'previous_value' => $previous, 'new_value' => $value, 'notes' => $notes,
            ]);
        });

        $this->events->dispatch(new TranslationSubmitted($translation, $this->id($by)));

        if ($approve) {
            $report = $this->approve((int) $translation->getKey(), $by, $notes);

            if ($report->skipped !== []) {
                throw new ProsettaException('Saved, but not approved: '.reset($report->skipped).'.');
            }
        }

        return $translation->refresh();
    }

    /** @param int|list<int> $translationIds */
    public function approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null): ApproveReport {
        $report = new ApproveReport;

        foreach ((array) $translationIds as $id) {
            $translation = $this->load((int) $id);
            $this->authorizer->authorize($by, Ability::Review, $translation->locale);

            if (($reason = $this->refusal($translation, $by)) !== null) {
                $report->skipped[(int) $translation->getKey()] = $reason;

                continue;
            }

            DB::transaction(function () use ($translation, $by, $notes): void {
                $translation->update([
                    'approved_value' => $translation->value, 'approved_source_hash' => $translation->source_hash,
                    'status' => TranslationStatus::Approved, 'reviewed_by' => $this->id($by), 'reviewed_at' => now(),
                ]);
                $translation->reviews()->create([
                    'reviewer_id' => $this->id($by), 'action' => ReviewAction::Approved,
                    'new_value' => $translation->value, 'notes' => $notes,
                ]);
            });

            $report->approved[] = (int) $translation->getKey();
            $this->events->dispatch(new TranslationApproved($translation, $this->id($by)));
        }

        return $report;
    }

    public function reject(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation {
        $translation = $this->load($translationId);
        $this->authorizer->authorize($by, Ability::Review, $translation->locale);

        DB::transaction(function () use ($translation, $by, $notes): void {
            $translation->update(['status' => TranslationStatus::Rejected, 'reviewed_by' => $this->id($by), 'reviewed_at' => now()]);
            $translation->reviews()->create([
                'reviewer_id' => $this->id($by), 'action' => ReviewAction::Rejected,
                'previous_value' => $translation->value, 'notes' => $notes,
            ]);
        });

        $this->events->dispatch(new TranslationRejected($translation, $this->id($by)));

        return $translation->refresh();
    }

    /** Approves every current draft or needs-review candidate for a locale (optionally one namespace or group). */
    public function approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null): ApproveReport {
        $this->authorizer->authorize($by, Ability::Review, $locale);
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $f = Settings::table('files');
        $model = Settings::model('translation');

        $ids = $model::query()->select("$t.id")
            ->join($k, "$k.id", '=', "$t.key_id")
            ->join($f, "$f.id", '=', "$k.file_id")
            ->where("$t.locale", $locale)
            ->whereNull("$k.obsolete_at")
            ->whereIn("$t.status", [TranslationStatus::Draft->value, TranslationStatus::NeedsReview->value])
            ->whereColumn("$t.source_hash", "$k.source_hash")
            ->when($namespace !== null, fn ($query) => $query->where("$f.namespace", $namespace))
            ->when($group !== null, fn ($query) => $query->where("$f.group", $group))
            ->orderBy("$t.id")
            ->pluck("$t.id")
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->approve($ids, $by);
    }

    private function refusal(Translation $translation, ?Authenticatable $by): ?string {
        if ($translation->value === null) {
            return 'empty';
        }

        if ($translation->status === TranslationStatus::Rejected) {
            return 'rejected';
        }

        if ($translation->hasBlockingIssues()) {
            return 'issues';
        }

        if ($translation->status === TranslationStatus::Approved
            && $translation->approved_value === $translation->value
            && $translation->approved_source_hash === $translation->source_hash) {
            return 'already_approved';
        }

        if ($by !== null && ! (bool) config('prosetta.review.allow_self_approval', true)) {
            $last = $translation->reviews()
                ->whereIn('action', [ReviewAction::Edited->value, ReviewAction::Submitted->value])
                ->latest('id')
                ->first();

            if ($last !== null && $last->reviewer_id === $this->id($by)) {
                return 'self_approval';
            }
        }

        return null;
    }

    private function load(int $id): Translation {
        $model = Settings::model('translation');

        return $model::query()->with('key.file')->findOrFail($id);
    }

    private function id(?Authenticatable $by): ?string {
        return $by === null ? null : (string) $by->getAuthIdentifier();
    }
}
```

`src/Review/ReviewItem.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Support\WorkState;

/** One row of a review queue, ready to become Inertia props. */
final readonly class ReviewItem {
    /** @param list<array{code: string, severity: string, message: string}> $issues */
    public function __construct(
        public int $translationId,
        public string $keyRef,
        public string $locale,
        public string $source,
        public ?string $candidate,
        public ?string $approved,
        public string $status,
        public string $origin,
        public bool $stale,
        public array $issues,
        public ?string $context,
        public ?int $maxLength,
        public ?string $aiModel,
        public ?string $aiProvider,
    ) {}

    public static function fromTranslation(Translation $translation): self {
        $key = $translation->key;
        $candidateStale = $translation->value !== null && $translation->source_hash !== $key->source_hash;

        return new self(
            (int) $translation->getKey(),
            $key->ref()->toString(),
            $translation->locale,
            $key->source_value,
            $translation->value,
            $translation->approved_value,
            $translation->status->value,
            $translation->origin->value,
            WorkState::isStale($key, $translation) || $candidateStale,
            $translation->issues ?? [],
            $key->context,
            $key->max_length,
            $translation->ai_model,
            $translation->ai_provider,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
```

`src/Review/ReviewQueue.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Support\Settings;

final class ReviewQueue {
    /**
     * @param array{status?: string|list<string>, stale?: bool, namespace?: string, group?: string, origin?: string, issues?: bool, search?: string} $filters
     * @return LengthAwarePaginator<int, ReviewItem>
     */
    public function forLocale(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator {
        $t = Settings::table('translations');
        $k = Settings::table('keys');
        $f = Settings::table('files');
        $model = Settings::model('translation');

        $statuses = array_map(
            fn ($status) => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
            (array) ($filters['status'] ?? [TranslationStatus::Draft, TranslationStatus::NeedsReview]),
        );

        $query = $model::query()->select("$t.*")
            ->join($k, "$k.id", '=', "$t.key_id")
            ->join($f, "$f.id", '=', "$k.file_id")
            ->where("$t.locale", $locale)
            ->whereNull("$k.obsolete_at")
            ->with('key.file');

        if (($filters['stale'] ?? false) === true) {
            $query->where(fn ($where) => $where
                ->whereIn("$t.status", $statuses)
                ->orWhere(fn ($stale) => $stale->whereNotNull("$t.approved_source_hash")->whereColumn("$t.approved_source_hash", '!=', "$k.source_hash")));
        } else {
            $query->whereIn("$t.status", $statuses);
        }

        foreach (['namespace' => "$f.namespace", 'group' => "$f.group", 'origin' => "$t.origin"] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        if (($filters['issues'] ?? false) === true) {
            $query->whereNotNull("$t.issues");
        }

        $search = $filters['search'] ?? null;

        if (is_string($search) && $search !== '') {
            $query->where(fn ($where) => $where->where("$k.source_value", 'like', "%$search%")->orWhere("$t.value", 'like', "%$search%"));
        }

        return $query->orderBy("$f.namespace")->orderBy("$f.group")->orderBy("$k.id")
            ->paginate($perPage)
            ->through(fn (Translation $translation) => ReviewItem::fromTranslation($translation));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add the review service and per-locale review queue" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 12: The AI driver contract, runner, queued job and translator

**Files:**
- Create:
  - `src/Contracts/TranslationDriver.php`, `src/Data/{TranslationItem,TranslationBatch,TranslationBatchResult}.php`, `src/Testing/FakeTranslationDriver.php`
  - `src/Translation/{TranslationRunner,TranslateReport,Translator}.php`, `src/Jobs/TranslateBatch.php`
  - `src/Events/TranslationDrafted.php`, `src/Exceptions/MissingDriverException.php`
  - `tests/Feature/Translation/TranslationRunnerTest.php`, `tests/Feature/Translation/TranslatorTest.php`
- Modify: `src/ProsettaServiceProvider.php`

**Interfaces:**
- Consumes: `LocaleSource`, `KeyFinder`, `PlaceholderGuard`, `Issue`, `WorkState::needsWork()`, `LocaleCode::isVariantOf()`, models.
- Produces:
  - `interface TranslationDriver { translate(TranslationBatch $batch): TranslationBatchResult; }`
  - `TranslationItem(string $id, string $keyRef, string $source, ?string $context, ?int $maxLength, array $placeholders, ?string $previous)`
  - `TranslationBatch(string $sourceLocale, LocaleDescriptor $target, ?string $variantOf, ?string $model, array $items, array $feedback = [])` with `withItems()` and `withFeedback()`
  - `TranslationBatchResult(array $values, string $provider, string $model, int $inputTokens, int $outputTokens, ?string $invocationId = null)`
  - `FakeTranslationDriver` (echo mode appends ` [code]`; `dropPlaceholders()`, `recasePlaceholders()`, `fixOnRetry()`, `omitValues()`; public `$calls`)
  - `TranslationRunner::run(string $locale, array $keyIds, bool $force = false): TranslateReport` and `TranslationRunner::apportion(int $total, array $weights): array`
  - `TranslateReport` (`drafted`, `withIssues`, `failed` lists of `"{locale} {ref}"`; `skipped`, `inputTokens`, `outputTokens` ints; `merge()`, `toArray()`)
  - `Translator::workList(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false): array` (locale → file id → key ids)
  - `Translator::translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true): Batch|TranslateReport`
  - `TranslateBatch(string $locale, int $fileId, array $keyIds, bool $force = false)` job
  - Event `TranslationDrafted(Translation $translation)`; `MissingDriverException`; rate limiter `prosetta-ai`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Translation/TranslationRunnerTest.php`:

```php
<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\MissingDriverException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function fakeDriver(): FakeTranslationDriver {
    $fake = new FakeTranslationDriver;
    app()->instance(TranslationDriver::class, $fake);

    return $fake;
}

function keyIds(string ...$refs): array {
    return array_map(fn (string $ref) => app(KeyFinder::class)->find($ref)->id, $refs);
}

it('drafts missing keys with provenance and a review row', function () {
    fakeDriver();
    config()->set('prosetta.ai.model', 'gpt-test');
    $ids = keyIds('identity::onboarding.toast.accessCode.capReached', 'identity::onboarding.toast.accessCode.timedOut');

    $report = app(TranslationRunner::class)->run('es', $ids);
    $draft = Translation::query()->where('key_id', $ids[0])->where('locale', 'es')->first();

    expect($report->drafted)->toBe(['es identity::onboarding.toast.accessCode.capReached', 'es identity::onboarding.toast.accessCode.timedOut'])
        ->and($draft->value)->toBe('Registration has a :minutes-minute limit, so the access code was released. Enter it again to start over. [es]')
        ->and($draft->status)->toBe(TranslationStatus::Draft)
        ->and($draft->origin)->toBe(TranslationOrigin::Ai)
        ->and($draft->ai_provider)->toBe('fake')
        ->and($draft->ai_model)->toBe('gpt-test')
        ->and($draft->ai_invocation_id)->toBe('fake-1')
        ->and($draft->approved_value)->toBeNull()
        ->and($draft->reviews()->first()->action)->toBe(ReviewAction::Submitted)
        ->and($report->inputTokens)->toBe((int) Translation::query()->where('locale', 'es')->sum('input_tokens'));
});

it('apportions tokens exactly across a call', function () {
    expect(TranslationRunner::apportion(10, ['a' => 1, 'b' => 1, 'c' => 1]))->toBe(['a' => 4, 'b' => 3, 'c' => 3])
        ->and(array_sum(TranslationRunner::apportion(1234, ['x' => 17, 'y' => 250])))->toBe(1234);
});

it('skips keys that do not need work unless forced', function () {
    $fake = fakeDriver();
    $ids = keyIds('identity::onboarding.toast.accessCode.inUse');

    expect(app(TranslationRunner::class)->run('es', $ids)->skipped)->toBe(1)
        ->and($fake->calls)->toBe([])
        ->and(app(TranslationRunner::class)->run('es', $ids, force: true)->drafted)->toHaveCount(1);
});

it('retries once with feedback and keeps the fix', function () {
    $fake = fakeDriver()->fixOnRetry();

    $report = app(TranslationRunner::class)->run('es', keyIds('identity::onboarding.toast.accessCode.capReached'));

    expect($fake->calls)->toHaveCount(2)
        ->and(array_values($fake->calls[1]->feedback)[0][0])->toContain(':minutes')
        ->and($report->withIssues)->toBe([])
        ->and(Translation::query()->where('locale', 'es')->latest('id')->first()->value)->toContain(':minutes');
});

it('saves a still-broken result as a draft with its issues', function () {
    fakeDriver()->dropPlaceholders();

    $report = app(TranslationRunner::class)->run('es', keyIds('identity::onboarding.toast.accessCode.capReached'));
    $draft = Translation::query()->where('locale', 'es')->latest('id')->first();

    expect($report->withIssues)->toBe(['es identity::onboarding.toast.accessCode.capReached'])
        ->and($draft->hasBlockingIssues())->toBeTrue()
        ->and($draft->status)->toBe(TranslationStatus::Draft);
});

it('reports keys the driver returned nothing for', function () {
    fakeDriver()->omitValues();

    expect(app(TranslationRunner::class)->run('es', keyIds('identity::onboarding.toast.accessCode.capReached'))->failed)
        ->toBe(['es identity::onboarding.toast.accessCode.capReached']);
});

it('tells the driver about variants and per-locale models', function () {
    $fake = fakeDriver();
    config()->set('prosetta.ai.models', ['en_GB' => 'small-model']);

    app(TranslationRunner::class)->run('en_GB', keyIds('auth.failed'));

    expect($fake->calls[0]->variantOf)->toBe('en')
        ->and($fake->calls[0]->model)->toBe('small-model')
        ->and($fake->calls[0]->target->englishName)->toBe('British English');
});

it('explains a missing driver', function () {
    expect(fn () => app(TranslationRunner::class)->run('es', keyIds('auth.throttle')))->toThrow(MissingDriverException::class);
});
```

`tests/Feature/Translation/TranslatorTest.php`:

```php
<?php

use Illuminate\Bus\PendingBatch;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use LonelyLights\Prosetta\Translation\TranslateReport;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

it('builds a work list of missing and stale keys per locale and file', function () {
    $work = app(Translator::class)->workList(['es'], ['identity']);
    $file = app(KeyFinder::class)->find('identity::onboarding.toast.accessCode.capReached')->file_id;

    expect(array_keys($work))->toBe(['es'])
        ->and($work['es'][$file])->toHaveCount(2);
});

it('narrows the work list to named keys', function () {
    $id = app(KeyFinder::class)->find('auth.throttle')->id;
    $work = app(Translator::class)->workList([], [], ['auth.throttle']);

    expect(array_keys($work))->toBe(['es', 'ar', 'en_GB'])
        ->and(collect($work)->flatten()->all())->toBe([$id, $id, $id]);
});

it('queues one batch of jobs on the configured queue', function () {
    Bus::fake();
    config()->set('prosetta.ai.batch', 2);

    app(Translator::class)->translate(['es'], ['identity']);

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === 'prosetta:translate'
        && $batch->jobs->count() === 1
        && $batch->queue() === 'translations'
        && $batch->jobs->first() instanceof TranslateBatch);
});

it('runs inline when asked', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);

    $report = app(Translator::class)->translate(['es'], ['identity'], queue: false);

    expect($report)->toBeInstanceOf(TranslateReport::class)
        ->and($report->drafted)->toHaveCount(2);
});

it('rate-limits and de-duplicates its jobs', function () {
    $middleware = (new TranslateBatch('es', 1, [1, 2]))->middleware();

    expect($middleware[0])->toBeInstanceOf(RateLimited::class)
        ->and($middleware[1])->toBeInstanceOf(WithoutOverlapping::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Translation`
Expected: FAIL: interface `LonelyLights\Prosetta\Contracts\TranslationDriver` not found.

- [ ] **Step 3: Implement the contract, DTOs and fake**

`src/Contracts/TranslationDriver.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Contracts;

use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;

/**
 * The host's AI. Prosetta ships no implementation. Keep drivers stateless:
 * a batch in, a result out, no Eloquent on either side.
 */
interface TranslationDriver {
    public function translate(TranslationBatch $batch): TranslationBatchResult;
}
```

`src/Data/TranslationItem.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

final readonly class TranslationItem {
    /** @param list<string> $placeholders */
    public function __construct(
        public string $id,
        public string $keyRef,
        public string $source,
        public ?string $context = null,
        public ?int $maxLength = null,
        public array $placeholders = [],
        public ?string $previous = null,
    ) {}
}
```

`src/Data/TranslationBatch.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/**
 * Everything a driver needs for one call. variantOf is set when the target
 * is a regional variant of the source (en_GB of en): adapt spelling and
 * usage, and return the source unchanged when nothing differs. feedback
 * lists the guard's complaints per item id on a retry.
 */
final readonly class TranslationBatch {
    /**
     * @param list<TranslationItem> $items
     * @param array<string, list<string>> $feedback
     */
    public function __construct(
        public string $sourceLocale,
        public LocaleDescriptor $target,
        public ?string $variantOf,
        public ?string $model,
        public array $items,
        public array $feedback = [],
    ) {}

    /** @param list<TranslationItem> $items */
    public function withItems(array $items): self {
        return new self($this->sourceLocale, $this->target, $this->variantOf, $this->model, $items, $this->feedback);
    }

    /** @param array<string, list<string>> $feedback */
    public function withFeedback(array $feedback): self {
        return new self($this->sourceLocale, $this->target, $this->variantOf, $this->model, $this->items, $feedback);
    }
}
```

`src/Data/TranslationBatchResult.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Data;

/** Values keyed by item id, plus what the call cost. */
final readonly class TranslationBatchResult {
    /** @param array<string, string> $values */
    public function __construct(
        public array $values,
        public string $provider,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $invocationId = null,
    ) {}
}
```

`src/Testing/FakeTranslationDriver.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Testing;

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Data\TranslationItem;

/**
 * A deterministic driver for tests, Prosetta's and hosts' alike. By default
 * it echoes the source with " [code]" appended, which keeps placeholders,
 * plural ranges and HTML intact.
 */
final class FakeTranslationDriver implements TranslationDriver {
    /** @var list<TranslationBatch> */
    public array $calls = [];

    private string $mode = 'echo';

    public function dropPlaceholders(): self {
        $this->mode = 'drop';

        return $this;
    }

    public function recasePlaceholders(): self {
        $this->mode = 'recase';

        return $this;
    }

    public function fixOnRetry(): self {
        $this->mode = 'drop-then-fix';

        return $this;
    }

    public function omitValues(): self {
        $this->mode = 'omit';

        return $this;
    }

    public function translate(TranslationBatch $batch): TranslationBatchResult {
        $this->calls[] = $batch;
        $retry = $batch->feedback !== [];
        $values = [];

        foreach ($batch->items as $item) {
            if ($this->mode === 'omit') {
                continue;
            }

            $value = $item->source.' ['.$batch->target->code.']';

            $values[$item->id] = match (true) {
                $this->mode === 'drop', $this->mode === 'drop-then-fix' && ! $retry => (string) preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '', $value),
                $this->mode === 'recase' => (string) preg_replace_callback('/:([a-z])/', fn (array $m) => ':'.strtoupper($m[1]), $value),
                default => $value,
            };
        }

        $tokens = array_sum(array_map(fn (TranslationItem $item) => strlen($item->source), $batch->items));

        return new TranslationBatchResult($values, 'fake', $batch->model ?? 'fake-model', $tokens, $tokens, 'fake-'.count($this->calls));
    }
}
```

- [ ] **Step 4: Implement the runner, report, job and translator**

`src/Exceptions/MissingDriverException.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

final class MissingDriverException extends ProsettaException {
    public static function make(): self {
        return new self('No TranslationDriver is bound. Bind LonelyLights\\Prosetta\\Contracts\\TranslationDriver in a service provider, or set prosetta.ai.driver.');
    }
}
```

`src/Events/TranslationDrafted.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Models\Translation;

final class TranslationDrafted {
    public function __construct(public readonly Translation $translation) {}
}
```

`src/Translation/TranslateReport.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

final class TranslateReport {
    /** @var list<string> "{locale} {ref}" */
    public array $drafted = [];

    /** @var list<string> */
    public array $withIssues = [];

    /** @var list<string> */
    public array $failed = [];

    public int $skipped = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public function merge(self $other): void {
        $this->drafted = [...$this->drafted, ...$other->drafted];
        $this->withIssues = [...$this->withIssues, ...$other->withIssues];
        $this->failed = [...$this->failed, ...$other->failed];
        $this->skipped += $other->skipped;
        $this->inputTokens += $other->inputTokens;
        $this->outputTokens += $other->outputTokens;
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'drafted' => $this->drafted, 'with_issues' => $this->withIssues, 'failed' => $this->failed,
            'skipped' => $this->skipped, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens,
        ];
    }
}
```

`src/Translation/TranslationRunner.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationItem;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\TranslationDrafted;
use LonelyLights\Prosetta\Exceptions\MissingDriverException;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\LocaleCode;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;

/**
 * Translates one chunk of keys for one locale. Re-checks every key first, so
 * a queued job whose English changed, or that someone already handled, does
 * nothing. Results pass the guard; failures get one retry with feedback, and
 * anything still broken is saved as a draft carrying its issues.
 */
final class TranslationRunner {
    public function __construct(
        private readonly Container $container,
        private readonly LocaleSource $locales,
        private readonly PlaceholderGuard $guard,
        private readonly Dispatcher $events,
    ) {}

    /** @param list<int> $keyIds */
    public function run(string $locale, array $keyIds, bool $force = false): TranslateReport {
        $report = new TranslateReport;
        $target = $this->locales->find($locale) ?? throw new ProsettaException("Unknown locale [$locale].");
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');

        /** @var Collection<int, TranslationKey> $keys */
        $keys = $keyModel::query()->with('file')->whereKey($keyIds)->whereNull('obsolete_at')->get()->keyBy(fn (TranslationKey $key) => (int) $key->getKey());
        $existing = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $keyIds)->get()->keyBy('key_id');
        $keys = $keys->filter(fn (TranslationKey $key) => $force || WorkState::needsWork($key, $existing->get($key->getKey())));
        $report->skipped = count($keyIds) - $keys->count();

        if ($keys->isEmpty()) {
            return $report;
        }

        $driver = $this->driver();
        $items = $keys->map(fn (TranslationKey $key) => new TranslationItem(
            (string) $key->getKey(),
            $key->ref()->toString(),
            $key->source_value,
            $key->context,
            $key->max_length,
            $key->placeholders ?? [],
            $existing->get($key->getKey())?->approved_value,
        ))->values()->all();

        $source = $this->locales->source();
        $batch = new TranslationBatch($source, $target, LocaleCode::isVariantOf($locale, $source) ? $source : null, $this->model($locale), $items);
        $outcomes = $this->attempt($driver, $batch, $locale);

        for ($retries = (int) config('prosetta.ai.retries_on_issues', 1); $retries > 0; $retries--) {
            $failing = array_filter($outcomes, fn (array $outcome) => $this->blocking($outcome['issues']));

            if ($failing === []) {
                break;
            }

            $feedback = array_map(fn (array $outcome) => array_map(fn (Issue $issue) => $issue->message, $outcome['issues']), $failing);
            $retryItems = array_values(array_filter($items, fn (TranslationItem $item) => array_key_exists($item->id, $failing)));
            $retried = $this->attempt($driver, $batch->withItems($retryItems)->withFeedback($feedback), $locale);

            foreach ($retried as $id => $outcome) {
                $outcome['input'] += $outcomes[$id]['input'];
                $outcome['output'] += $outcomes[$id]['output'];
                $outcomes[$id] = $outcome;
            }
        }

        foreach ($outcomes as $id => $outcome) {
            $this->persist($keys->get((int) $id), $existing->get((int) $id), $locale, $outcome, $report);
        }

        return $report;
    }

    /**
     * Splits a call's tokens across its items by weight; the remainder goes to the first item.
     *
     * @param array<array-key, int> $weights
     * @return array<array-key, int>
     */
    public static function apportion(int $total, array $weights): array {
        $sum = array_sum($weights);
        $shares = [];
        $given = 0;

        foreach ($weights as $id => $weight) {
            $shares[$id] = $sum > 0 ? intdiv($total * $weight, $sum) : 0;
            $given += $shares[$id];
        }

        if ($shares !== []) {
            $shares[array_key_first($shares)] += $total - $given;
        }

        return $shares;
    }

    /** @return array<array-key, array{value: string|null, issues: list<Issue>, provider: string, model: string, invocation: string|null, input: int, output: int}> */
    private function attempt(TranslationDriver $driver, TranslationBatch $batch, string $locale): array {
        $result = $driver->translate($batch);
        $weights = [];

        foreach ($batch->items as $item) {
            $weights[$item->id] = max(1, mb_strlen($item->source));
        }

        $input = self::apportion($result->inputTokens, $weights);
        $output = self::apportion($result->outputTokens, $weights);
        $outcomes = [];

        foreach ($batch->items as $item) {
            $value = $result->values[$item->id] ?? null;

            $outcomes[$item->id] = [
                'value' => is_string($value) ? $value : null,
                'issues' => is_string($value)
                    ? $this->guard->check($item->source, $value, $locale)
                    : [Issue::error('missing_value', 'The driver returned no value for this key.')],
                'provider' => $result->provider,
                'model' => $result->model,
                'invocation' => $result->invocationId,
                'input' => $input[$item->id],
                'output' => $output[$item->id],
            ];
        }

        return $outcomes;
    }

    /** @param array{value: string|null, issues: list<Issue>, provider: string, model: string, invocation: string|null, input: int, output: int} $outcome */
    private function persist(TranslationKey $key, ?Translation $existing, string $locale, array $outcome, TranslateReport $report): void {
        $ref = $locale.' '.$key->ref()->toString();
        $report->inputTokens += $outcome['input'];
        $report->outputTokens += $outcome['output'];

        if ($outcome['value'] === null) {
            $report->failed[] = $ref;

            return;
        }

        $translationModel = Settings::model('translation');
        /** @var Translation $translation */
        $translation = $existing ?? new $translationModel(['key_id' => $key->getKey(), 'locale' => $locale]);

        DB::transaction(function () use ($translation, $key, $outcome): void {
            $translation->fill([
                'value' => $outcome['value'], 'source_hash' => $key->source_hash,
                'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai,
                'issues' => Issue::store($outcome['issues']),
                'ai_provider' => $outcome['provider'], 'ai_model' => $outcome['model'],
                'input_tokens' => $outcome['input'], 'output_tokens' => $outcome['output'],
                'ai_invocation_id' => $outcome['invocation'],
            ])->save();

            $translation->reviews()->create([
                'reviewer_id' => null, 'action' => ReviewAction::Submitted,
                'new_value' => $outcome['value'], 'notes' => 'Machine translation by '.$outcome['model'].'.',
            ]);
        });

        $report->drafted[] = $ref;

        if ($this->blocking($outcome['issues'])) {
            $report->withIssues[] = $ref;
        }

        $this->events->dispatch(new TranslationDrafted($translation));
    }

    /** @param list<Issue> $issues */
    private function blocking(array $issues): bool {
        foreach ($issues as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    private function model(string $locale): ?string {
        $models = (array) config('prosetta.ai.models', []);
        $model = $models[$locale] ?? config('prosetta.ai.model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    private function driver(): TranslationDriver {
        if (! $this->container->bound(TranslationDriver::class)) {
            throw MissingDriverException::make();
        }

        return $this->container->make(TranslationDriver::class);
    }
}
```

`src/Jobs/TranslateBatch.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LonelyLights\Prosetta\Translation\TranslationRunner;

/** One chunk of one file for one locale. Idempotent: the runner re-checks before calling the AI. */
final class TranslateBatch implements ShouldQueue {
    use Batchable, InteractsWithQueue, Queueable;

    /** @param list<int> $keyIds */
    public function __construct(
        public string $locale,
        public int $fileId,
        public array $keyIds,
        public bool $force = false,
    ) {}

    /** @return list<object> */
    public function middleware(): array {
        return [
            new RateLimited('prosetta-ai'),
            (new WithoutOverlapping("prosetta:{$this->locale}:{$this->fileId}"))->releaseAfter(30),
        ];
    }

    public function handle(TranslationRunner $runner): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $runner->run($this->locale, $this->keyIds, $this->force);
    }
}
```

`src/Translation/Translator.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Translation;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Jobs\TranslateBatch;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;

final class Translator {
    public function __construct(
        private readonly LocaleSource $locales,
        private readonly KeyFinder $finder,
        private readonly TranslationRunner $runner,
    ) {}

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys key references
     * @return array<string, array<int, list<int>>> locale => file id => key ids
     */
    public function workList(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false): array {
        $targets = array_values(array_filter(
            array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets()),
            fn (string $code) => $locales === [] || in_array($code, $locales, true),
        ));
        $keyTable = Settings::table('keys');
        $fileTable = Settings::table('files');
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');

        $query = $keyModel::query()->select("$keyTable.*")
            ->join($fileTable, "$fileTable.id", '=', "$keyTable.file_id")
            ->whereNull("$keyTable.obsolete_at")
            ->orderBy("$keyTable.id");

        if ($namespaces !== []) {
            $query->whereIn("$fileTable.namespace", $namespaces);
        }

        if ($keys !== []) {
            $query->whereKey(array_map(
                fn (string $ref) => $this->finder->find($ref)?->getKey() ?? throw new ProsettaException("No key [$ref]."),
                $keys,
            ));
        }

        $candidates = $query->get();
        $work = [];

        foreach ($targets as $locale) {
            $existing = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $candidates->modelKeys())->get()->keyBy('key_id');

            foreach ($candidates as $key) {
                /** @var TranslationKey $key */
                if ($force || WorkState::needsWork($key, $existing->get($key->getKey()))) {
                    $work[$locale][(int) $key->file_id][] = (int) $key->getKey();
                }
            }
        }

        return $work;
    }

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys
     */
    public function translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true): Batch|TranslateReport {
        $work = $this->workList($locales, $namespaces, $keys, $force);
        $size = max(1, (int) config('prosetta.ai.batch', 25));

        if (! $queue) {
            $report = new TranslateReport;

            foreach ($work as $locale => $files) {
                foreach ($files as $ids) {
                    foreach (array_chunk($ids, $size) as $chunk) {
                        $report->merge($this->runner->run($locale, $chunk, $force));
                    }
                }
            }

            return $report;
        }

        $jobs = [];

        foreach ($work as $locale => $files) {
            foreach ($files as $fileId => $ids) {
                foreach (array_chunk($ids, $size) as $chunk) {
                    $jobs[] = new TranslateBatch($locale, (int) $fileId, $chunk, $force);
                }
            }
        }

        if ($jobs === []) {
            return new TranslateReport;
        }

        $pending = Bus::batch($jobs)->name('prosetta:translate')->allowFailures();
        $connection = config('prosetta.queue.connection');

        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        return $pending->onQueue((string) config('prosetta.queue.name', 'translations'))->dispatch();
    }
}
```

Replace `src/ProsettaServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');

        $this->app->singleton(Authorizer::class);
        $this->app->bind(LocaleSource::class, fn (Application $app) => $app->make((string) config('prosetta.locales.source', DatabaseLocaleSource::class)));

        $driver = config('prosetta.ai.driver');

        if (is_string($driver) && $driver !== '') {
            $this->app->bindIf(TranslationDriver::class, $driver);
        }
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');
        }

        foreach (Ability::cases() as $ability) {
            Gate::define($ability->gate(), fn (Authenticatable $user, ?string $locale = null): bool => $this->app->make(Authorizer::class)->allows($user, $ability, $locale));
        }

        RateLimiter::for('prosetta-ai', fn (): Limit => Limit::perMinute(max(1, (int) config('prosetta.queue.rate_per_minute', 60))));
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add the translation driver contract, guarded runner and queued batches" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 13: Export

**Files:**
- Create: `src/Export/PhpArrayWriter.php`, `src/Export/JsonWriter.php`, `src/Export/Exporter.php`, `src/Export/ExportReport.php`, `src/Events/ExportCompleted.php`, `tests/Unit/Export/WritersTest.php`, `tests/Feature/Export/ExporterTest.php`

**Interfaces:**
- Consumes: `RootDiscovery`, `LangReader::read()/path()`, `LocaleSource`, `PathFilter::excluded()`, `Fingerprint`, `Translation::hasBlockingIssues()`, `FakeTranslationDriver` and `TranslationRunner` (tests).
- Produces:
  - `PhpArrayWriter::render(array $data, string $header = ''): string`
  - `JsonWriter::render(array $values): string`
  - `Exporter::export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false): ExportReport`
  - `ExportReport` (`bool $dryRun`, `array $written`, `$unchanged`, `$refused` path lists, `array $keys` path → count, `toArray()`)
  - Event `ExportCompleted(ExportReport $report)`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Export/WritersTest.php`:

```php
<?php

use LonelyLights\Prosetta\Export\JsonWriter;
use LonelyLights\Prosetta\Export\PhpArrayWriter;

it('writes readable PHP with short arrays and a header', function () {
    $php = (new PhpArrayWriter)->render(['title' => 'Ajustes', 'steps' => ['Abre el menú', 'Elige un idioma']], "Line one\nLine two");

    expect($php)->toBe(<<<'PHP'
<?php

/*
 * Line one
 * Line two
 */

return [
    'title' => 'Ajustes',
    'steps' => [
        0 => 'Abre el menú',
        1 => 'Elige un idioma',
    ],
];

PHP);
});

it('round-trips awkward characters', function () {
    $values = ['quote' => "It's", 'slash' => 'C:\\path\\', 'newline' => "Two\nlines", 'emoji' => 'Listo ✅', 'rtl' => 'مرحبًا، :name'];
    $path = tempnam(sys_get_temp_dir(), 'prosetta').'.php';
    file_put_contents($path, (new PhpArrayWriter)->render($values));

    expect(require $path)->toBe($values);
    unlink($path);
});

it('writes an empty array compactly', function () {
    expect((new PhpArrayWriter)->render([]))->toBe("<?php\n\nreturn [];\n");
});

it('writes JSON with readable unicode and slashes, always as an object', function () {
    expect((new JsonWriter)->render(['Save changes' => 'Guardar cambios', 'a/b' => 'ñ']))
        ->toBe("{\n    \"Save changes\": \"Guardar cambios\",\n    \"a/b\": \"ñ\"\n}\n")
        ->and((new JsonWriter)->render([]))->toBe("{}\n");
});
```

`tests/Feature/Export/ExporterTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\ExportCompleted;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

const SPANISH_IN_USE = 'Este código de acceso se está usando para registrarse en otro dispositivo. Si ese registro no se completa, quedará libre en unos minutos.';

it('exports approved values only by default, in source key order', function () {
    app(Exporter::class)->export(['es'], ['identity']);

    expect(file_get_contents($this->fixture.'/modules/Identity/Lang/es/onboarding.php'))->toBe(<<<PHP
<?php

/*
 * Generated by Prosetta from identity::en/onboarding.php. Edit the source file or use Prosetta;
 * hand edits here are imported for review on the next sync.
 */

return [
    'toast' => [
        'accessCode' => [
            'inUse' => 'Este código de acceso se está usando para registrarse en otro dispositivo. Si ese registro no se completa, quedará libre en unos minutos.',
        ],
    ],
];

PHP);
});

it('exports drafts when asked, with placeholders intact', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    app(Translator::class)->translate(['es'], ['identity'], queue: false);

    app(Exporter::class)->export(['es'], ['identity'], includeDrafts: true);

    expect(file_get_contents($this->fixture.'/modules/Identity/Lang/es/onboarding.php'))->toBe(<<<PHP
<?php

/*
 * Generated by Prosetta from identity::en/onboarding.php. Edit the source file or use Prosetta;
 * hand edits here are imported for review on the next sync.
 */

return [
    'toast' => [
        'accessCode' => [
            'capReached' => 'Registration has a :minutes-minute limit, so the access code was released. Enter it again to start over. [es]',
            'inUse' => 'Este código de acceso se está usando para registrarse en otro dispositivo. Si ese registro no se completa, quedará libre en unos minutos.',
            'timedOut' => 'Your registration paused for more than five minutes, so the access code was released. Enter it again to continue. [es]',
        ],
    ],
];

PHP);
});

it('never exports a candidate with blocking issues, even with drafts', function () {
    app()->instance(TranslationDriver::class, (new FakeTranslationDriver)->dropPlaceholders());
    app(Translator::class)->translate(['es'], ['identity'], queue: false);

    app(Exporter::class)->export(['es'], ['identity'], includeDrafts: true);

    expect(file_get_contents($this->fixture.'/modules/Identity/Lang/es/onboarding.php'))
        ->not->toContain('capReached')
        ->toContain('timedOut');
});

it('never writes the source locale', function () {
    $before = file_get_contents($this->fixture.'/lang/en/auth.php');

    $report = app(Exporter::class)->export();

    expect(file_get_contents($this->fixture.'/lang/en/auth.php'))->toBe($before)
        ->and(collect($report->written)->filter(fn (string $path) => str_contains($path, '/en/')))->toBeEmpty();
});

it('does not rewrite unchanged files', function () {
    app(Exporter::class)->export(['es']);
    $report = app(Exporter::class)->export(['es']);

    expect($report->written)->toBe([])
        ->and($report->unchanged)->toContain($this->fixture.'/lang/es/auth.php');
});

it('refuses excluded paths', function () {
    config()->set('prosetta.exclude_paths', [$this->fixture.'/lang/es']);
    $before = file_get_contents($this->fixture.'/lang/es/auth.php');

    $report = app(Exporter::class)->export(['es']);

    expect($report->refused)->toContain($this->fixture.'/lang/es/auth.php')
        ->and(file_get_contents($this->fixture.'/lang/es/auth.php'))->toBe($before);
});

it('writes nothing on a dry run', function () {
    $before = file_get_contents($this->fixture.'/lang/es/auth.php');

    $report = app(Exporter::class)->export(['es'], dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->written)->toContain($this->fixture.'/lang/es/auth.php')
        ->and(file_get_contents($this->fixture.'/lang/es/auth.php'))->toBe($before)
        ->and(is_file($this->fixture.'/lang/ar/auth.php'))->toBeFalse();
});

it('round-trips: exporting then syncing finds no hand edits', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    app(Translator::class)->translate(queue: false);
    app(Exporter::class)->export(includeDrafts: true);

    $report = app(Syncer::class)->sync();

    expect($report->handEdits)->toBe([])
        ->and($report->imported)->toBe(0)
        ->and($report->hasChanges())->toBeFalse();
});

it('exports JSON keys whole with readable unicode', function () {
    $key = app(KeyFinder::class)->find('json:Version 2.0 is ready.');
    Translation::query()->create([
        'key_id' => $key->id, 'locale' => 'es', 'value' => 'La versión 2.0 está lista.', 'source_hash' => $key->source_hash,
        'approved_value' => 'La versión 2.0 está lista.', 'approved_source_hash' => $key->source_hash, 'status' => TranslationStatus::Approved,
    ]);

    app(Exporter::class)->export(['es']);

    expect(json_decode(file_get_contents($this->fixture.'/lang/es.json'), true))->toBe([
        'Save changes' => 'Guardar cambios', 'Version 2.0 is ready.' => 'La versión 2.0 está lista.',
    ])->and(file_get_contents($this->fixture.'/lang/es.json'))->toContain('versión');
});

it('exports list arrays back as lists', function () {
    foreach (['admin/settings.steps.0' => 'Abre el menú', 'admin/settings.steps.1' => 'Elige un idioma'] as $ref => $value) {
        $key = app(KeyFinder::class)->find($ref);
        Translation::query()->create([
            'key_id' => $key->id, 'locale' => 'es', 'value' => $value, 'source_hash' => $key->source_hash,
            'approved_value' => $value, 'approved_source_hash' => $key->source_hash, 'status' => TranslationStatus::Approved,
        ]);
    }

    app(Exporter::class)->export(['es']);

    expect(require $this->fixture.'/lang/es/admin/settings.php')->toBe(['steps' => ['Abre el menú', 'Elige un idioma']])
        ->and(app(Syncer::class)->sync()->handEdits)->toBe([]);
});

it('drops obsolete keys but keeps the file', function () {
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    app(Exporter::class)->export(['es']);

    expect(require $this->fixture.'/lang/es/auth.php')->toBe([]);
});

it('records what it wrote and announces the export', function () {
    Event::fake([ExportCompleted::class]);

    app(Exporter::class)->export(['es'], ['identity']);
    $inUse = app(KeyFinder::class)->find('identity::onboarding.toast.accessCode.inUse')->translations->firstWhere('locale', 'es');

    expect($inUse->exported_hash)->toBe(Fingerprint::of(SPANISH_IN_USE));
    Event::assertDispatched(ExportCompleted::class);
});

it('exports what reviewers approved without needing drafts', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    app(Translator::class)->translate(['es'], ['identity'], queue: false);
    app(ReviewService::class)->approveClean('es', 'identity');

    app(Exporter::class)->export(['es'], ['identity']);
    $accessCode = (require $this->fixture.'/modules/Identity/Lang/es/onboarding.php')['toast']['accessCode'];

    expect(array_keys($accessCode))->toBe(['capReached', 'inUse', 'timedOut'])
        ->and($accessCode['capReached'])->toContain(':minutes');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Export tests/Feature/Export`
Expected: FAIL: classes in `LonelyLights\Prosetta\Export` not found.

- [ ] **Step 3: Implement**

`src/Export/PhpArrayWriter.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

/** Renders a lang array as readable PHP: short arrays, four-space indents, single quotes, real Unicode. */
final class PhpArrayWriter {
    /** @param array<array-key, mixed> $data */
    public function render(array $data, string $header = ''): string {
        $output = "<?php\n\n";

        if ($header !== '') {
            $lines = array_map(fn (string $line) => rtrim(' * '.$line), explode("\n", $header));
            $output .= "/*\n".implode("\n", $lines)."\n */\n\n";
        }

        return $output.'return '.$this->array($data, 0).";\n";
    }

    /** @param array<array-key, mixed> $data */
    private function array(array $data, int $depth): string {
        if ($data === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($data as $key => $value) {
            $rendered = is_array($value) ? $this->array($value, $depth + 1) : $this->string((string) $value);
            $lines[] = $indent.(is_int($key) ? (string) $key : $this->string($key)).' => '.$rendered.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    private function string(string $value): string {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
```

`src/Export/JsonWriter.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

final class JsonWriter {
    /** @param array<array-key, string> $values */
    public function render(array $values): string {
        return json_encode(
            $values,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
```

`src/Export/ExportReport.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

final class ExportReport {
    /** @var list<string> */
    public array $written = [];

    /** @var list<string> */
    public array $unchanged = [];

    /** @var list<string> */
    public array $refused = [];

    /** @var array<string, int> path => keys in the file */
    public array $keys = [];

    public function __construct(public readonly bool $dryRun = false) {}

    /** @return array<string, mixed> */
    public function toArray(): array {
        return ['dry_run' => $this->dryRun, 'written' => $this->written, 'unchanged' => $this->unchanged, 'refused' => $this->refused, 'keys' => $this->keys];
    }
}
```

`src/Events/ExportCompleted.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Events;

use LonelyLights\Prosetta\Export\ExportReport;

final class ExportCompleted {
    public function __construct(public readonly ExportReport $report) {}
}
```

`src/Export/Exporter.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Export;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Discovery\LangReader;
use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Discovery\RootDiscovery;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Events\ExportCompleted;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationFile;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\PathFilter;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Writes target-locale files for root and module lang folders in the source
 * file's key order. Never writes the source locale, never deletes a file,
 * never writes an excluded path, and never rewrites an unchanged file.
 */
final class Exporter {
    public function __construct(
        private readonly RootDiscovery $discovery,
        private readonly LangReader $reader,
        private readonly LocaleSource $locales,
        private readonly PathFilter $filter,
        private readonly PhpArrayWriter $php,
        private readonly JsonWriter $json,
        private readonly Filesystem $files,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     */
    public function export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false): ExportReport {
        $includeDrafts ??= (bool) config('prosetta.export.include_drafts', false);
        $report = new ExportReport($dryRun);
        $source = $this->locales->source();
        $targets = array_values(array_filter(
            array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets()),
            fn (string $code) => $code !== $source && ($locales === [] || in_array($code, $locales, true)),
        ));
        $fileModel = Settings::model('file');

        foreach ($this->discovery->roots($namespaces === [] ? null : $namespaces) as $root) {
            foreach ($fileModel::query()->where('namespace', $root->namespace)->orderBy('group')->get() as $file) {
                /** @var TranslationFile $file */
                $order = array_map('strval', array_keys($this->reader->read($root, $source, $file->group, $file->format)));
                $keys = $file->keys()->whereNull('obsolete_at')->get()->keyBy(fn (TranslationKey $key) => $key->key);

                foreach ($targets as $locale) {
                    $this->exportFile($root, $file, $order, $keys, $source, $locale, $includeDrafts, $report);
                }
            }
        }

        if (! $dryRun) {
            $this->events->dispatch(new ExportCompleted($report));
        }

        return $report;
    }

    /**
     * @param list<string> $order
     * @param Collection<string, TranslationKey> $keys
     */
    private function exportFile(LangRoot $root, TranslationFile $file, array $order, Collection $keys, string $source, string $locale, bool $includeDrafts, ExportReport $report): void {
        $path = $this->reader->path($root, $locale, $file->group, $file->format);

        if ($this->filter->excluded($path)) {
            $report->refused[] = $path;

            return;
        }

        $translationModel = Settings::model('translation');
        $translations = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $keys->modelKeys())->get()->keyBy('key_id');
        $values = [];
        $written = [];

        foreach ($order as $key) {
            $model = $keys->get($key);
            $translation = $model === null ? null : $translations->get($model->getKey());
            $value = $translation === null ? null : $this->pick($translation, $includeDrafts);

            if ($value !== null) {
                $values[$key] = $value;
                $written[] = [$translation, $value];
            }
        }

        $exists = is_file($path);

        if ($values === [] && ! $exists) {
            return;
        }

        $content = $file->format === FileFormat::Json
            ? $this->json->render($values)
            : $this->php->render(Arr::undot($values), $this->header($file, $source));

        if ($exists && $this->files->get($path) === $content) {
            $report->unchanged[] = $path;
        } else {
            if (! $report->dryRun) {
                $this->write($path, $content);
            }

            $report->written[] = $path;
        }

        $report->keys[$path] = count($values);

        if (! $report->dryRun) {
            foreach ($written as [$translation, $value]) {
                $hash = Fingerprint::of($value);

                if ($translation->exported_hash !== $hash) {
                    $translation->update(['exported_hash' => $hash]);
                }
            }
        }
    }

    private function pick(Translation $translation, bool $includeDrafts): ?string {
        if ($includeDrafts
            && $translation->value !== null
            && $translation->status !== TranslationStatus::Rejected
            && ! $translation->hasBlockingIssues()) {
            return $translation->value;
        }

        return $translation->approved_value;
    }

    private function header(TranslationFile $file, string $source): string {
        $prefix = $file->namespace === KeyRef::ROOT ? '' : $file->namespace.'::';

        return "Generated by Prosetta from {$prefix}{$source}/{$file->group}.php. Edit the source file or use Prosetta;\nhand edits here are imported for review on the next sync.";
    }

    private function write(string $path, string $content): void {
        $this->files->ensureDirectoryExists(dirname($path));
        $temporary = $path.'.prosetta-'.bin2hex(random_bytes(4));
        $this->files->put($temporary, $content);

        if (! @rename($temporary, $path)) {
            $this->files->delete($temporary);

            throw new ProsettaException("Could not write [$path].");
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Export approved (or draft) translations to root and module lang folders" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 14: Stats

**Files:**
- Create: `src/Queries/Stats.php`, `tests/Feature/Queries/StatsTest.php`

**Interfaces:**
- Consumes: `LocaleSource`, `WorkState`, models.
- Produces: `Stats::summary(?string $locale = null): array<string, array<string, array{keys:int, approved:int, drafts:int, needs_review:int, stale:int, missing:int, issues:int, tokens:int}>>` (locale → namespace → counts); `Stats::outstanding(): int` (missing + stale + drafts + needs_review + issues across targets).

- [ ] **Step 1: Write the failing test**

`tests/Feature/Queries/StatsTest.php`:

```php
<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Queries\Stats;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;
use LonelyLights\Prosetta\Translation\Translator;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

it('counts per locale and namespace', function () {
    expect(app(Stats::class)->summary('es'))->toBe([
        'es' => [
            '*' => ['keys' => 10, 'approved' => 2, 'drafts' => 0, 'needs_review' => 0, 'stale' => 0, 'missing' => 8, 'issues' => 0, 'tokens' => 0],
            'identity' => ['keys' => 3, 'approved' => 1, 'drafts' => 0, 'needs_review' => 0, 'stale' => 0, 'missing' => 2, 'issues' => 0, 'tokens' => 0],
        ],
    ]);
});

it('counts drafts and their tokens', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    app(Translator::class)->translate(['es'], ['identity'], queue: false);

    $identity = app(Stats::class)->summary('es')['es']['identity'];

    expect($identity['drafts'])->toBe(2)
        ->and($identity['missing'])->toBe(0)
        ->and($identity['tokens'])->toBeGreaterThan(0);
});

it('counts stale translations', function () {
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Wrong details.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    expect(app(Stats::class)->summary('es')['es']['*']['stale'])->toBe(1)
        ->and(app(Stats::class)->summary('es')['es']['*']['approved'])->toBe(1);
});

it('adds up everything outstanding across targets', function () {
    # es: 8 + 2 missing; ar: 13 missing; en_GB: 12 missing
    expect(app(Stats::class)->outstanding())->toBe(35);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Queries/StatsTest.php`
Expected: FAIL: class `LonelyLights\Prosetta\Queries\Stats` not found.

- [ ] **Step 3: Implement**

`src/Queries/Stats.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\WorkState;

final class Stats {
    private const EMPTY = ['keys' => 0, 'approved' => 0, 'drafts' => 0, 'needs_review' => 0, 'stale' => 0, 'missing' => 0, 'issues' => 0, 'tokens' => 0];

    public function __construct(private readonly LocaleSource $locales) {}

    /** @return array<string, array<string, array{keys: int, approved: int, drafts: int, needs_review: int, stale: int, missing: int, issues: int, tokens: int}>> */
    public function summary(?string $locale = null): array {
        $codes = array_values(array_filter(
            array_map(fn (LocaleDescriptor $descriptor) => $descriptor->code, $this->locales->targets()),
            fn (string $code) => $locale === null || $code === $locale,
        ));
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');
        $keys = $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get();
        $summary = [];

        foreach ($codes as $code) {
            $translations = $translationModel::query()->where('locale', $code)->get()->keyBy('key_id');

            foreach ($keys as $key) {
                /** @var TranslationKey $key */
                $namespace = $key->file->namespace;
                $summary[$code][$namespace] ??= self::EMPTY;
                $row = &$summary[$code][$namespace];
                $translation = $translations->get($key->getKey());
                $row['keys']++;

                if (WorkState::isMissing($key, $translation)) {
                    $row['missing']++;
                }

                if (WorkState::isStale($key, $translation)) {
                    $row['stale']++;
                }

                if ($translation !== null) {
                    if ($translation->approved_value !== null && ! WorkState::isStale($key, $translation)) {
                        $row['approved']++;
                    }

                    if ($translation->status === TranslationStatus::Draft) {
                        $row['drafts']++;
                    }

                    if ($translation->status === TranslationStatus::NeedsReview) {
                        $row['needs_review']++;
                    }

                    if ($translation->hasBlockingIssues()) {
                        $row['issues']++;
                    }

                    $row['tokens'] += (int) $translation->input_tokens + (int) $translation->output_tokens;
                }

                unset($row);
            }
        }

        return $summary;
    }

    public function outstanding(): int {
        $total = 0;

        foreach ($this->summary() as $namespaces) {
            foreach ($namespaces as $row) {
                $total += $row['missing'] + $row['stale'] + $row['drafts'] + $row['needs_review'] + $row['issues'];
            }
        }

        return $total;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add per-locale, per-namespace stats and an outstanding count" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 15: ProsettaManager, facade, commands and migration publishing

**Files:**
- Create:
  - `src/ProsettaManager.php`, `src/Facades/Prosetta.php`
  - `src/Console/{InstallCommand,SyncCommand,TranslateCommand,ReviewCommand,ExportCommand,RenameCommand,StatsCommand}.php`
  - `tests/Feature/ManagerTest.php`, `tests/Feature/Console/CommandsTest.php`
- Modify: `src/ProsettaServiceProvider.php`

**Interfaces:**
- Consumes: every service above.
- Produces the facade API from spec §11:
  - `sync(?array $namespaces = null, bool $check = false, ?Authenticatable $by = null): SyncReport`
  - `translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true, ?Authenticatable $by = null): Batch|TranslateReport`
  - `reviewQueue(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator`
  - `edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation`
  - `approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null): ApproveReport`
  - `approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null): ApproveReport`
  - `reject(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation`
  - `export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, ?Authenticatable $by = null): ExportReport`
  - `rename(string $from, string $to, ?Authenticatable $by = null): TranslationKey`
  - `stats(?string $locale = null): array`
  - `lookup(string $keyRef): ?TranslationKey`
  - `authorizeUsing(Closure $callback): void`
- Commands: `prosetta:install`, `prosetta:sync`, `prosetta:translate`, `prosetta:review`, `prosetta:export`, `prosetta:rename`, `prosetta:stats`. Publish tags: `prosetta-config`, `prosetta-migrations`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ManagerTest.php`:

```php
<?php

use Illuminate\Auth\Access\AuthorizationException;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Facades\Prosetta;
use LonelyLights\Prosetta\Models\TranslationKey;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
});

it('checks without keeping anything', function () {
    $report = Prosetta::sync(check: true);

    expect($report->outstanding)->toBe(35)
        ->and($report->added)->toHaveCount(13)
        ->and(TranslationKey::query()->count())->toBe(0);
});

it('syncs for real through the facade', function () {
    Prosetta::sync();

    expect(TranslationKey::query()->count())->toBe(13)
        ->and(Prosetta::lookup('identity::onboarding.toast.accessCode.inUse')?->translations)->toHaveCount(1)
        ->and(Prosetta::stats('es')['es']['identity']['missing'])->toBe(2);
});

it('requires Manage to sync, export or rename as a user', function () {
    Prosetta::authorizeUsing(fn ($user, Ability $ability) => $ability !== Ability::Manage);

    expect(fn () => Prosetta::sync(by: $this->user()))->toThrow(AuthorizationException::class)
        ->and(fn () => Prosetta::export(by: $this->user()))->toThrow(AuthorizationException::class)
        ->and(fn () => Prosetta::rename('auth.failed', 'auth.throttle', $this->user()))->toThrow(AuthorizationException::class);
});

it('requires Translate for each locale it drafts as a user', function () {
    Prosetta::sync();
    Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale) => $locale === 'es');

    expect(fn () => Prosetta::translate(['ar'], by: $this->user()))->toThrow(AuthorizationException::class);
});
```

`tests/Feature/Console/CommandsTest.php`:

```php
<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
});

it('fails the check while work is outstanding', function () {
    $this->artisan('prosetta:sync --check')->assertExitCode(1);
});

it('syncs, translates, approves and exports from the console', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);

    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:translate --locale=es --namespace=identity --sync')->assertSuccessful();
    $this->artisan('prosetta:review es --approve-clean')->assertSuccessful();
    $this->artisan('prosetta:export --locale=es --namespace=identity')->assertSuccessful();

    expect(require $this->fixture.'/modules/Identity/Lang/es/onboarding.php')->toHaveCount(1)
        ->and(file_get_contents($this->fixture.'/modules/Identity/Lang/es/onboarding.php'))->toContain(':minutes-minute limit');
});

it('lists the review queue', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:translate --locale=es --namespace=identity --sync')->assertSuccessful();

    $this->artisan('prosetta:review es')
        ->expectsOutputToContain('identity::onboarding.toast.accessCode.capReached')
        ->assertSuccessful();
});

it('previews an export without writing', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:export --locale=es --dry-run')
        ->expectsOutputToContain('would write')
        ->assertSuccessful();
});

it('prints stats and refuses a bad rename', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:stats --locale=es')->expectsOutputToContain('identity')->assertSuccessful();
    $this->artisan('prosetta:rename auth.failed auth.nope')->assertFailed();
});

it('publishes config and migrations on install', function () {
    $this->artisan('prosetta:install')->assertSuccessful();

    expect(is_file(config_path('prosetta.php')))->toBeTrue();
    unlink(config_path('prosetta.php'));
    foreach (glob(database_path('migrations/*prosetta*.php')) as $migration) {
        unlink($migration);
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ManagerTest.php tests/Feature/Console`
Expected: FAIL: class `LonelyLights\Prosetta\Facades\Prosetta` not found.

- [ ] **Step 3: Implement the manager and facade**

`src/ProsettaManager.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Export\ExportReport;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Queries\Stats;
use LonelyLights\Prosetta\Review\ApproveReport;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Renamer;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Sync\SyncReport;
use LonelyLights\Prosetta\Translation\TranslateReport;
use LonelyLights\Prosetta\Translation\Translator;

/** The public surface: what host controllers, commands and the facade call. */
class ProsettaManager {
    public function __construct(
        private readonly Syncer $syncer,
        private readonly Renamer $renamer,
        private readonly Translator $translator,
        private readonly ReviewService $reviews,
        private readonly ReviewQueue $queue,
        private readonly Exporter $exporter,
        private readonly Stats $stats,
        private readonly KeyFinder $finder,
        private readonly Authorizer $authorizer,
        private readonly LocaleSource $locales,
    ) {}

    /**
     * With $check, runs the whole sync inside a transaction that is rolled
     * back: nothing is kept, and $report->outstanding says how much work waits.
     *
     * @param list<string>|null $namespaces
     */
    public function sync(?array $namespaces = null, bool $check = false, ?Authenticatable $by = null): SyncReport {
        $this->authorizer->authorize($by, Ability::Manage);

        if (! $check) {
            return $this->syncer->sync($namespaces);
        }

        DB::beginTransaction();

        try {
            $report = $this->syncer->sync($namespaces, quiet: true);
            $report->outstanding = $this->stats->outstanding();
        } finally {
            DB::rollBack();
        }

        return $report;
    }

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     * @param list<string> $keys
     */
    public function translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true, ?Authenticatable $by = null): Batch|TranslateReport {
        $codes = $locales !== [] ? $locales : array_map(fn (LocaleDescriptor $locale) => $locale->code, $this->locales->targets());

        foreach ($codes as $code) {
            $this->authorizer->authorize($by, Ability::Translate, $code);
        }

        return $this->translator->translate($locales, $namespaces, $keys, $force, $queue);
    }

    /** @param array<string, mixed> $filters */
    public function reviewQueue(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator {
        return $this->queue->forLocale($locale, $filters, $perPage);
    }

    public function edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation {
        return $this->reviews->edit($translationId, $value, $by, $notes, $approve);
    }

    /** @param int|list<int> $translationIds */
    public function approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null): ApproveReport {
        return $this->reviews->approve($translationIds, $by, $notes);
    }

    public function approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null): ApproveReport {
        return $this->reviews->approveClean($locale, $namespace, $group, $by);
    }

    public function reject(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation {
        return $this->reviews->reject($translationId, $by, $notes);
    }

    /**
     * @param list<string> $locales
     * @param list<string> $namespaces
     */
    public function export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, ?Authenticatable $by = null): ExportReport {
        $this->authorizer->authorize($by, Ability::Manage);

        return $this->exporter->export($locales, $namespaces, $includeDrafts, $dryRun);
    }

    public function rename(string $from, string $to, ?Authenticatable $by = null): TranslationKey {
        $this->authorizer->authorize($by, Ability::Manage);

        return $this->renamer->rename($from, $to);
    }

    /** @return array<string, array<string, array<string, int>>> */
    public function stats(?string $locale = null): array {
        return $this->stats->summary($locale);
    }

    public function lookup(string $keyRef): ?TranslationKey {
        return $this->finder->find($keyRef);
    }

    public function authorizeUsing(Closure $callback): void {
        $this->authorizer->using($callback);
    }
}
```

`src/Facades/Prosetta.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Facades;

use Illuminate\Support\Facades\Facade;
use LonelyLights\Prosetta\ProsettaManager;

/**
 * @method static \LonelyLights\Prosetta\Sync\SyncReport sync(?array $namespaces = null, bool $check = false, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \Illuminate\Bus\Batch|\LonelyLights\Prosetta\Translation\TranslateReport translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator reviewQueue(string $locale, array $filters = [], int $perPage = 50)
 * @method static \LonelyLights\Prosetta\Models\Translation edit(int $translationId, string $value, ?\Illuminate\Contracts\Auth\Authenticatable $by, ?string $notes = null, bool $approve = false)
 * @method static \LonelyLights\Prosetta\Review\ApproveReport approve(int|array $translationIds, ?\Illuminate\Contracts\Auth\Authenticatable $by, ?string $notes = null)
 * @method static \LonelyLights\Prosetta\Review\ApproveReport approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \LonelyLights\Prosetta\Models\Translation reject(int $translationId, ?\Illuminate\Contracts\Auth\Authenticatable $by, ?string $notes = null)
 * @method static \LonelyLights\Prosetta\Export\ExportReport export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static \LonelyLights\Prosetta\Models\TranslationKey rename(string $from, string $to, ?\Illuminate\Contracts\Auth\Authenticatable $by = null)
 * @method static array stats(?string $locale = null)
 * @method static \LonelyLights\Prosetta\Models\TranslationKey|null lookup(string $keyRef)
 * @method static void authorizeUsing(\Closure $callback)
 *
 * @see ProsettaManager
 */
final class Prosetta extends Facade {
    protected static function getFacadeAccessor(): string {
        return ProsettaManager::class;
    }
}
```

- [ ] **Step 4: Implement the commands**

`src/Console/InstallCommand.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command {
    protected $signature = 'prosetta:install';

    protected $description = "Publish Prosetta's config and migrations.";

    public function handle(): int {
        $this->call('vendor:publish', ['--tag' => 'prosetta-config']);
        $this->call('vendor:publish', ['--tag' => 'prosetta-migrations']);
        $this->components->info('Next: migrate, bind a TranslationDriver, register Prosetta::authorizeUsing(), then run prosetta:sync.');

        return self::SUCCESS;
    }
}
```

`src/Console/SyncCommand.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;

final class SyncCommand extends Command {
    protected $signature = 'prosetta:sync
        {--namespace=* : Only these namespaces (* is the root lang folder)}
        {--check : Change nothing; exit 1 when anything is missing, stale, unreviewed or broken}';

    protected $description = "Read the source-language files and bring Prosetta's keys and imported translations up to date.";

    public function handle(ProsettaManager $prosetta): int {
        $namespaces = $this->option('namespace');
        $check = (bool) $this->option('check');
        $report = $prosetta->sync($namespaces === [] ? null : $namespaces, $check);

        $this->table(
            ['Added', 'Changed', 'Restored', 'Obsolete', 'Imported', 'Hand edits'],
            [[count($report->added), count($report->changed), count($report->restored), count($report->obsoleted), $report->imported, count($report->handEdits)]],
        );

        foreach ($report->handEdits as $edit) {
            $this->components->warn("Hand edit imported for review: $edit");
        }

        if (! $check) {
            return self::SUCCESS;
        }

        if ($report->outstanding === 0) {
            $this->components->info('Nothing outstanding.');

            return self::SUCCESS;
        }

        $this->components->error("{$report->outstanding} translation(s) missing, stale, awaiting review or broken. Run prosetta:stats for detail.");

        return self::FAILURE;
    }
}
```

`src/Console/TranslateCommand.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;

final class TranslateCommand extends Command {
    protected $signature = 'prosetta:translate
        {--locale=* : Only these locales}
        {--namespace=* : Only these namespaces}
        {--key=* : Only these key references}
        {--force : Retranslate keys that are already current}
        {--sync : Run now instead of queueing}';

    protected $description = 'Draft missing and stale translations with the bound TranslationDriver.';

    public function handle(ProsettaManager $prosetta): int {
        $result = $prosetta->translate(
            $this->option('locale'),
            $this->option('namespace'),
            $this->option('key'),
            (bool) $this->option('force'),
            ! $this->option('sync'),
        );

        if ($result instanceof Batch) {
            $this->components->info("Queued batch {$result->id} with {$result->totalJobs} job(s) on the ".config('prosetta.queue.name').' queue.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d draft(s), %d with issues, %d failed; %d input / %d output tokens.',
            count($result->drafted), count($result->withIssues), count($result->failed), $result->inputTokens, $result->outputTokens,
        ));

        foreach ($result->withIssues as $ref) {
            $this->components->warn("Needs attention: $ref");
        }

        return $result->failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
```

`src/Console/ReviewCommand.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LonelyLights\Prosetta\ProsettaManager;
use LonelyLights\Prosetta\Review\ReviewItem;

final class ReviewCommand extends Command {
    protected $signature = 'prosetta:review
        {locale : The locale to review}
        {--approve-clean : Approve every current candidate without blocking issues}
        {--namespace= : Only this namespace}
        {--limit=20 : Rows to list}';

    protected $description = 'List what waits for review in a locale, or approve every clean candidate.';

    public function handle(ProsettaManager $prosetta): int {
        $locale = (string) $this->argument('locale');
        $namespace = $this->option('namespace');

        if ($this->option('approve-clean')) {
            $report = $prosetta->approveClean($locale, is_string($namespace) ? $namespace : null);
            $this->components->info(count($report->approved)." approved, ".count($report->skipped).' skipped.');

            foreach ($report->skipped as $id => $reason) {
                $this->components->warn("Translation $id skipped: $reason");
            }

            return self::SUCCESS;
        }

        $page = $prosetta->reviewQueue($locale, array_filter(['namespace' => $namespace]), (int) $this->option('limit'));

        $this->table(['Key', 'Status', 'Stale', 'Issues', 'Candidate'], array_map(fn (ReviewItem $item) => [
            $item->keyRef, $item->status, $item->stale ? 'yes' : '', count($item->issues), Str::limit((string) $item->candidate, 60),
        ], $page->items()));
        $this->components->info("{$page->total()} waiting in $locale.");

        return self::SUCCESS;
    }
}
```

`src/Console/ExportCommand.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;

final class ExportCommand extends Command {
    protected $signature = 'prosetta:export
        {--locale=* : Only these locales}
        {--namespace=* : Only these namespaces}
        {--include-drafts : Also write unreviewed drafts that have no blocking issues}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Write target-locale lang files from approved (or draft) translations.';

    public function handle(ProsettaManager $prosetta): int {
        $dryRun = (bool) $this->option('dry-run');
        $report = $prosetta->export($this->option('locale'), $this->option('namespace'), $this->option('include-drafts') ? true : null, $dryRun);

        foreach ($report->written as $path) {
            $this->line(($dryRun ? 'would write ' : 'wrote ').$path.' ('.($report->keys[$path] ?? 0).' keys)');
        }

        foreach ($report->refused as $path) {
            $this->components->warn("Refused (excluded path): $path");
        }

        $this->components->info(count($report->written).' written, '.count($report->unchanged).' unchanged, '.count($report->refused).' refused.');

        return self::SUCCESS;
    }
}
```

`src/Console/RenameCommand.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\ProsettaManager;

final class RenameCommand extends Command {
    protected $signature = 'prosetta:rename {from : The old key reference} {to : The new key reference}';

    protected $description = 'Move translations from a renamed key to its new name (rename it in the source file and sync first).';

    public function handle(ProsettaManager $prosetta): int {
        try {
            $key = $prosetta->rename((string) $this->argument('from'), (string) $this->argument('to'));
        } catch (ProsettaException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Moved translations to {$key->ref()}.");

        return self::SUCCESS;
    }
}
```

`src/Console/StatsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Console;

use Illuminate\Console\Command;
use LonelyLights\Prosetta\ProsettaManager;

final class StatsCommand extends Command {
    protected $signature = 'prosetta:stats {--locale= : Only this locale}';

    protected $description = 'Show translation progress per locale and namespace.';

    public function handle(ProsettaManager $prosetta): int {
        $locale = $this->option('locale');
        $rows = [];

        foreach ($prosetta->stats(is_string($locale) ? $locale : null) as $code => $namespaces) {
            foreach ($namespaces as $namespace => $row) {
                $rows[] = [$code, $namespace, ...array_values($row)];
            }
        }

        $this->table(['Locale', 'Namespace', 'Keys', 'Approved', 'Drafts', 'Needs review', 'Stale', 'Missing', 'Issues', 'Tokens'], $rows);

        return self::SUCCESS;
    }
}
```

Replace `src/ProsettaServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Console\ExportCommand;
use LonelyLights\Prosetta\Console\InstallCommand;
use LonelyLights\Prosetta\Console\RenameCommand;
use LonelyLights\Prosetta\Console\ReviewCommand;
use LonelyLights\Prosetta\Console\StatsCommand;
use LonelyLights\Prosetta\Console\SyncCommand;
use LonelyLights\Prosetta\Console\TranslateCommand;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

final class ProsettaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/prosetta.php', 'prosetta');

        $this->app->singleton(Authorizer::class);
        $this->app->singleton(ProsettaManager::class);
        $this->app->bind(LocaleSource::class, fn (Application $app) => $app->make((string) config('prosetta.locales.source', DatabaseLocaleSource::class)));

        $driver = config('prosetta.ai.driver');

        if (is_string($driver) && $driver !== '') {
            $this->app->bindIf(TranslationDriver::class, $driver);
        }
    }

    public function boot(): void {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/prosetta.php' => config_path('prosetta.php')], 'prosetta-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'prosetta-migrations');
            $this->commands([
                InstallCommand::class, SyncCommand::class, TranslateCommand::class, ReviewCommand::class,
                ExportCommand::class, RenameCommand::class, StatsCommand::class,
            ]);
        }

        foreach (Ability::cases() as $ability) {
            Gate::define($ability->gate(), fn (Authenticatable $user, ?string $locale = null): bool => $this->app->make(Authorizer::class)->allows($user, $ability, $locale));
        }

        RateLimiter::for('prosetta-ai', fn (): Limit => Limit::perMinute(max(1, (int) config('prosetta.queue.rate_per_minute', 60))));
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add ProsettaManager, the facade and the Artisan commands" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

### Task 16: README, changelog and final verification

**Files:**
- Modify: `README.md` (replace), `CHANGELOG.md` (prepend)

**Interfaces:**
- Consumes: the whole public API; documents a host laravel/ai adapter using `Laravel\Ai\Promptable::prompt(string $prompt, array $attachments = [], $provider = null, ?string $model = null)`, `AgentResponse->invocationId`, `->usage->promptTokens`, `->usage->completionTokens`, `->meta->provider`, `->meta->model`, and `StructuredAgentResponse->structured`.

- [ ] **Step 1: Write `README.md`**

````markdown
# Prosetta

Translation workflow for Laravel. Your source-language lang files are the canonical keys. Prosetta drafts every other locale through an AI driver you supply, routes drafts through per-language human review, and writes approved text back to root and module lang folders. It's headless: your admin calls its services.

## How it works

1. **You edit the source locale's files** (`lang/en/*.php`, `lang/en.json`, `app/Modules/*/Lang/en/*.php`) by hand. Prosetta never writes them.
2. **`prosetta:sync`** reads them into keys. New keys become *missing* in every target locale. A changed English value makes existing translations *stale*. Removed keys become *obsolete*. Existing target files are imported as approved work; later hand edits to them are imported for review, never silently accepted.
3. **`prosetta:translate`** drafts missing and stale keys through your `TranslationDriver`. Every result is checked for placeholders (exact case), plural segments and HTML, gets one retry with feedback, and is saved as a draft with model, provider and tokens.
4. **Reviewers approve, edit or reject** per locale (`Prosetta::approve()`, `edit()`, `reject()`, or `prosetta:review`).
5. **`prosetta:export`** writes approved values (or drafts, if you choose) into each locale's files, in the source file's key order.

## Install

```bash
composer require lonely-lights/prosetta
php artisan prosetta:install   # publishes config/prosetta.php and the migrations
php artisan migrate
```

Target locales are rows in `prosetta_locales` where `active` (offered to members) or `translated` (maintained, even if not offered) is true. Codes must match your lang folder names exactly (`zh-CN`, `en_GB`) and can't change once created.

## Bind an AI driver

Prosetta ships no AI client. Implement `LonelyLights\Prosetta\Contracts\TranslationDriver`. With [laravel/ai](https://github.com/laravel/ai) it looks like this:

```php
namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class Translator implements Agent, HasStructuredOutput {
    use Promptable;

    public function instructions(): string {
        return <<<'TEXT'
        You translate user-interface strings for a web application.
        Keep every placeholder that starts with a colon (:name, :Name, :NAME) exactly as written, including its case.
        Keep plural segments separated by | and any {0} or [2,*] range markers. Keep HTML tags unchanged.
        Respect max_length when given. When variant_of is set, only adapt spelling and usage for that region,
        and return the text unchanged when nothing differs. Fix every listed problem on a retry.
        Return exactly one translation per id.
        TEXT;
    }

    public function schema(JsonSchema $schema): array {
        return [
            'translations' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'value' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
```

```php
namespace App\Services\Translation;

use App\Ai\Agents\Translator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Data\TranslationItem;

final readonly class LaravelAiTranslationDriver implements TranslationDriver {
    public function __construct(private Translator $agent) {}

    public function translate(TranslationBatch $batch): TranslationBatchResult {
        $response = $this->agent->prompt(json_encode([
            'from' => $batch->sourceLocale,
            'to' => ['code' => $batch->target->code, 'language' => $batch->target->englishName, 'script' => $batch->target->script],
            'variant_of' => $batch->variantOf,
            'items' => array_map(fn (TranslationItem $item) => [
                'id' => $item->id, 'text' => $item->source, 'context' => $item->context,
                'max_length' => $item->maxLength, 'previous_translation' => $item->previous,
                'problems_to_fix' => $batch->feedback[$item->id] ?? [],
            ], $batch->items),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), model: $batch->model);

        $rows = $response instanceof StructuredAgentResponse ? ($response->structured['translations'] ?? []) : [];

        return new TranslationBatchResult(
            values: array_column($rows, 'value', 'id'),
            provider: (string) ($response->meta->provider ?? 'unknown'),
            model: (string) ($response->meta->model ?? $batch->model ?? 'unknown'),
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
            invocationId: $response->invocationId,
        );
    }
}
```

Bind it in a service provider (or set `prosetta.ai.driver` to the class):

```php
$this->app->bind(\LonelyLights\Prosetta\Contracts\TranslationDriver::class, \App\Services\Translation\LaravelAiTranslationDriver::class);
```

Each translation stores its share of the call's tokens and the call's `ai_invocation_id`, so exact totals are one join away in any ledger keyed by laravel/ai's invocation id.

## Authorization

Decide access in one place. The callback receives the ability and, except for `Manage`, the locale:

```php
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Facades\Prosetta;

Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale): bool => match ($ability) {
    Ability::Manage => $user->can('translations.manage'),
    default => $user->can("translations.{$ability->value}.$locale"),
});
```

`Review` implies `Translate` for the same locale. With no callback, only the `local` environment is allowed. Prosetta also registers the gates `prosetta.translate`, `prosetta.review` and `prosetta.manage`, so `$user->can('prosetta.review', 'ar')` works in policies.

## Commands

| Command | What it does |
|---|---|
| `prosetta:sync [--namespace=*] [--check]` | Read source files, import target files. `--check` changes nothing and exits 1 when work is outstanding (use it in CI). |
| `prosetta:translate [--locale=*] [--namespace=*] [--key=*] [--force] [--sync]` | Draft missing and stale keys (queued on `prosetta.queue.name` unless `--sync`). |
| `prosetta:review {locale} [--approve-clean] [--namespace=]` | List the review queue, or approve every clean current candidate. |
| `prosetta:export [--locale=*] [--namespace=*] [--include-drafts] [--dry-run]` | Write target-locale files. |
| `prosetta:rename {from} {to}` | Move translations to a key you renamed in the source file (sync first). |
| `prosetta:stats [--locale=]` | Progress per locale and namespace. |

Key references use Laravel's notation: `identity::onboarding.toast.accessCode.inUse`, `auth.failed`, `json:Save changes`.

## Services for your own admin

`Prosetta::reviewQueue($locale, $filters)` returns a paginator of `ReviewItem` (key, source, candidate, approved value, status, stale flag, issues, provenance), ready for Inertia props. `edit()`, `approve()`, `approveClean()`, `reject()`, `export()`, `rename()`, `stats()` and `lookup()` complete the surface. Every method that acts on behalf of a user takes `?Authenticatable $by`; `null` means the system.

## Testing your integration

Bind `LonelyLights\Prosetta\Testing\FakeTranslationDriver` in tests. It echoes the source with ` [locale]` appended, and can be told to drop or recase placeholders, fix them on retry, or return nothing.
````

- [ ] **Step 2: Prepend to `CHANGELOG.md`**

```markdown
## Unreleased: rebuild

- Rebuilt from a blank slate as a headless package. Source-language lang files are the canonical keys; Prosetta reads them and writes only other locales.
- AI translation through a host-supplied `TranslationDriver`, with placeholder, plural and HTML checks, one guided retry, and per-translation provenance (provider, model, tokens, invocation id).
- Per-language review (`Translate`, `Review`, `Manage`) decided by one `Prosetta::authorizeUsing()` hook, plus Laravel gates.
- Discovers module lang folders from `loadTranslationsFrom()` hints; supports root PHP, root JSON, nested folders and regional variants.
- Export writes approved (optionally draft) values in source key order, atomically, never to the source locale or excluded paths.
- `Locale` keeps only translation fields, gains `translated`, widens codes to 35 characters, and makes codes immutable.
- Requires PHP 8.3 and Laravel 11, 12 or 13. The Blade UI, legacy file-writing services, queue table and statistics seeder are removed.
```

- [ ] **Step 3: Verify everything**

Run: `composer validate --strict && vendor/bin/pest`
Expected: `./composer.json is valid`, and the full suite PASSES with 0 failures and 0 skipped.

Run: `git status --short`
Expected: only `README.md` and `CHANGELOG.md` modified.

- [ ] **Step 4: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "Document the rebuilt Prosetta and its laravel/ai adapter" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2"
```

---

## After the plan

Not part of these tasks, but required before the work counts as finished:

1. A whole-branch code review of `rebuild/v1`.
2. Merge `rebuild/v1` into `main` only with your go-ahead, because merging changes what Undaunted runs immediately.
3. Write the Undaunted hand-off report, the 10 sections you specified, from the code as committed.
````
