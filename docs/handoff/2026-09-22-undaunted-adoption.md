# Prosetta rebuild — hand-off for Undaunted

You already know Undaunted well; this covers what changed in Prosetta (`lonely-lights/prosetta`, the path package at `C:\Websites\packages\prosetta`, reached through `vendor/lonely-lights/prosetta`) and how Undaunted adopts it. Everything below describes the code as committed at `47e16c4`.

> **Never delete, clean or `rm -rf` anything under `vendor/lonely-lights/prosetta`.** It is a junction to the package source; deleting through it has emptied the package before. The package folder also has its own `vendor/` for its tests, which shows up at `vendor/lonely-lights/prosetta/vendor/`. Leave it alone; Undaunted's autoloader ignores it.

## 1. Summary

Prosetta was rebuilt from a blank slate as a **headless** translation workflow. Your source-language lang files (`lang/en/*.php`, `lang/en.json`, `app/Modules/*/Lang/en/*.php`) are the canonical keys: Prosetta only reads them, and writes other locales.

- **Sync** turns those files into keys with source hashes, so missing, stale and obsolete states are derived, not stored. Existing target files import as approved work. Hand edits to target files are imported for review, never silently accepted.
- **AI translation** runs through a `TranslationDriver` that Undaunted supplies (a laravel/ai adapter). Every result is checked for placeholders (exact case, per plural form), plural ranges and HTML, gets one retry with feedback, and is saved as a draft carrying its provider, model, tokens and invocation id.
- **Review** is per language, and one `Prosetta::authorizeUsing()` hook decides who may do it.
- **Export** writes approved values (or drafts, if you ask) back into `lang/` and module `Lang/` folders, in the source file's key order. It refuses to overwrite anything Prosetta didn't write itself.

Why a rebuild: the old package wrote files directly with no review, didn't see module lang folders, didn't allow Laravel 13, and had int-only reviewer ids. Undaunted today uses only `Models\Locale`, which stays compatible.

## 2. Version and commits

- **Branch / SHA:** `main` at `47e16c4`, **pushed** to `origin` (`https://github.com/lonely-lights/prosetta`). There is no release tag yet; publishing timing is still to be decided.
- **Constraint:** `php ^8.3`; `illuminate/{auth,bus,cache,console,contracts,database,filesystem,queue,support,translation}` `^11.0|^12.0|^13.0`. Laravel 13 is allowed; the old `^10|^11|^12` blocked it.
- **Undaunted must run:** `composer update lonely-lights/prosetta`. The code is already live through the junction, but `composer.lock` still records the old metadata, and package discovery needs refreshing for the new `Prosetta` facade alias.

Commits since the old `76e9ebc` (newest first):

```
47e16c4 Close the deferred minors: plural detection, driver check, stats, search, bulk auth, lists and orphans
bdb9eae Add the Undaunted hand-off report and record post-MVP decisions
a1f7733 Address IDE review: typed constants, exception handling and guarded writes
70c0c6b Make stateless classes readonly and drop an unnecessary cast
d2f2b16 Document export conflicts, hand translation and the locales migration upgrade path
078c27c Invalidate OPcache around lang file reads and writes
bc21c1a Make edit-and-approve atomic and let people translate missing keys by hand
04a9916 Publish the locales migration separately, only when the table is missing
39efe4b Obsolete namespaces that a full sync no longer discovers
7c3b581 Keep throttled or overlapping translation jobs alive until they can run
ddec649 Check placeholders per plural form, matched by range marker
95eb5e5 Refuse to export over target content Prosetta did not write
a782292 Document the rebuilt Prosetta and its laravel/ai adapter
e3097d6 Add ProsettaManager, the facade and the Artisan commands
8462770 Add per-locale, per-namespace stats and an outstanding count
ecf5c8f Export approved (or draft) translations to root and module lang folders
faad525 Add the translation driver contract, guarded runner and queued batches
652e75d Add the review service and per-locale review queue
756391f Add KeyFinder and a rename that carries translations across
cf4ffe3 Sync source keys and import target files without overwriting reviewed work
b2f2749 Discover lang roots and read lang files with an Undaunted-shaped fixture
e37d53c Add the workflow schema, models and derived work states
e9193d1 Add per-language authorization through one host hook and gates
58ae1ab Add PlaceholderGuard for placeholders, plurals and HTML
3e8bab0 Add LocaleSource with database and config implementations
9ebee66 Add the PHP and JSON lang writers (Task 13, first part)
acd0e04 Add KeyRef for Laravel-style key references
8a6629f Add the lean Locale with a translated flag and immutable codes
72cbf4d Keep the Unit test directory so a bare pest run works
e5baa8b Start the blank-slate rebuild with config, settings and a testbench harness
5781d23 Add the rebuild implementation plan and record four spec amendments
bc74a54 Add Prosetta rebuild design spec
74979be Allow Laravel 13 and require PHP 8.3
be8c70d Add translated flag to Locale; widen locale code to 35
```

The design is `docs/superpowers/specs/2026-09-22-prosetta-rebuild-design.md` in the package. The package suite is 192 tests. Undaunted's full suite passed against `a1f7733` (699 passed, 2 skipped); `47e16c4` changes nothing Undaunted calls today.

Already in Undaunted: `a84056a` (the `translated` column, the 35-character code, the `en_GB`/`en_US` seed rows) and `ff19f8a` (removed the obsolete "gates Prosetta's own dashboard" test), both on `feat/responses-ledger`.

## 3. Breaking changes (only what Undaunted touches)

**`LonelyLights\Prosetta\Models\Locale`**
- **Unchanged:** the class, the `prosetta_locales` table, the `locale_initials` column, the `active()` / `default()` / `ordered()` scopes, `findByCode()`, `getDefault()` / `getDefaultCode()`, `getActiveCodes()`, `setAsDefault()`, `toggleActive()`, `getDisplayName()`, `isRtl()`, `getDirection()`, and the `code` accessor. `(new Locale)->getTable()` still works; it's now a `getTable()` override instead of a constructor assignment.
- **New:** `translated` (fillable, boolean cast), a `targets()` scope (`active OR translated`), and `toDescriptor()`.
- **Changed — codes are immutable.** Saving a changed `locale_initials` throws `LogicException` ("Locale codes are immutable…").
  - **No Undaunted change needed:** `UpdateLocaleRequest` never accepts `locale_initials`, and `UpdateController::FIELDS` only uses it to snapshot the audit before and after. Keep it that way.
- **Removed:** `getActiveLocales()`, `clearCache()`, the `activeLocales` container binding and its cache key. Undaunted never calls these, but still forgets the cache key.
  - **Undaunted change:** delete the two `Cache::forget('activeLocales')` lines in `AppServiceProvider::configureLocaleCache()` (keep the `ActiveLocales::forget()` calls), fix its docblock, and delete the same line in `Bridge/Http/Controllers/Locales/MakeDefaultController.php:48`, `ReorderController.php:39` and `database/seeders/LocalesSeeder.php:150`.

**`config/prosetta.php`**
- **Old keys are all gone:** `locales` (a flat list), `visibility`, `affixationType` / `affixationDefault`, `only` / `prefix` / `suffix` / `column` / `string`, `tableNames`, `models` (with nulls), `keyPattern`, `defaultBehavior`, `logChannel`, `routes`, `stack`.
- **New file:** replace it with §5. Undaunted's current file still works for now, because top-level keys merge with the package defaults, but it should be replaced.
- **Watch out:** `prosetta.locales` is now an array (`['source' => …, 'fallback' => [...]]`), no longer a list of codes.
  - **Undaunted change:** in `app/Services/Locales/ActiveLocales.php:78`, change `config('prosetta.locales', ['en'])` to `config('prosetta.locales.fallback', ['en'])`. In `tests/Feature/LocalesSeedTest.php:107`, change `config(['prosetta.locales' => [...]])` to `config(['prosetta.locales.fallback' => [...]])`.
- `routes` / `PROSETTA_ROUTES` no longer exist, because Prosetta has **no routes, views or controllers** anymore.

**`database/migrations/0050_locales/0050_01_01_000100_create_prosetta_locales_table.php`**
- **No change needed.** It already has `translated` and `string('locale_initials', 35)` (`a84056a`).
- **Four new tables are needed** (see §8, step 1).

**The Bridge's "Translations" button**
- `IndexController::prosettaReady()` flips to true as soon as `prosetta_files` exists, and `resources/js/modules/bridge/pages/Locales/Index.tsx:253` links to `/prosetta`, which is now a 404.
- **Undaunted change:** point it at the new Bridge review pages (§8, step 9), or hide it until they exist.
- `PermissionName::AccessProsetta` can stay, as the gate for those pages.

**Removed package classes** Undaunted never used: `Services\LangKeyService`, `FileSynchronizer`, `KeyManager`, `TranslationService`, `FileScanner`, the old `FileExporter`, `Traits\HasTranslations` (the old file-writing version), the queue model and table, `ProcessEntries`, all Blade views, routes and controllers, `InstallCommand`'s old stack prompts, and the statistics `LocaleSeeder`.

## 4. Final Locale schema

`prosetta_locales` (Undaunted's migration already matches):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, auto-increment, primary key | Stable key; **reference locales by `id`** from your own tables |
| `locale_initials` | string(35), unique | Code exactly as the folder is named (`zh-CN`, `en_GB`); immutable |
| `english_name` | string | Used in AI prompts |
| `native_name` | string | |
| `script` | string, nullable | AI hint |
| `rtl` | boolean, default false | |
| `active` | boolean, default false, indexed | Offered to members |
| `translated` | boolean, default false, indexed | Maintained by Prosetta even when not offered |
| `is_default` | boolean, default false, indexed | The visitor default, **not** the source locale |
| `sort_order` | integer, default 0 | |
| `created_at`, `updated_at` | timestamps | |

**Removed:** `total_speakers`, `native_speakers` and `origin` exist nowhere any more, not even in the package seeder, which is deleted.

**Extension point for Undaunted's own language data** (speaker counts, family, Ethnologue): keep it in an Undaunted table and attach it without subclassing:

```php
// AppServiceProvider::boot()
Locale::resolveRelationUsing('profile', fn (Locale $locale) => $locale->hasOne(LanguageProfile::class));
```

Ethnologue data describes *languages* (ISO 639-3), while Prosetta rows are *locales*, so if `en_GB` and `en_US` would share English's data, model it as many locales to one language. Subclassing through `config('prosetta.models.locale')` also works, and the model isn't `final`. `users.locale` storing the code is fine, because codes can't change.

**Target locales** are rows where `active OR translated`, excluding the source (`en`): today `es`, `ar`, `zh-CN`, `en_GB` and `en_US`.

## 5. Config

A complete `config/prosetta.php` for Undaunted:

```php
<?php

return [

    // Canonical keys: this locale's files. Prosetta reads them and never writes them.
    'source_locale' => env('PROSETTA_SOURCE_LOCALE', 'en'),

    // Lang roots. '*' = lang_path() (PHP groups + lang/en.json). Module namespaces are
    // discovered automatically from each ModuleServiceProvider::loadModuleTranslations()
    // call (loadTranslationsFrom(app/Modules/<Module>/Lang, '<module>')): bridge,
    // celestial, cohorts, engagement, identity, showcase. Nothing to list by hand.
    'namespaces' => [
        'discover' => true,
        'include' => ['*'],
        'exclude' => [],              // e.g. ['celestial'] to keep a module out of Prosetta
    ],

    // Only needed to add a root the translator doesn't know about.
    'paths' => [],

    // Skipped when reading, refused when writing. 'vendor' keeps packages' own translation
    // namespaces (and anything under vendor/) out, so export never writes into vendor/.
    'exclude_paths' => ['lang/vendor', 'vendor'],

    // Set true locally if you want drafts written to files while you work; keep false in CI.
    'export' => [
        'include_drafts' => env('PROSETTA_EXPORT_DRAFTS', false),
    ],

    // Set false once there is more than one reviewer per locale (requires a second person).
    'review' => [
        'allow_self_approval' => true,
    ],

    'ai' => [
        'driver' => App\Services\Translation\LaravelAiTranslationDriver::class, // or bind the contract yourself
        'model' => env('PROSETTA_AI_MODEL'),     // null = the agent's own default from config/ai.php
        'models' => [],                          // per-locale overrides, e.g. ['ar' => 'gpt-…']
        'batch' => 25,                           // keys per AI call
        'retries_on_issues' => 1,
    ],

    'queue' => [
        'connection' => env('PROSETTA_QUEUE_CONNECTION'),        // null = default (redis/Horizon)
        'name' => env('PROSETTA_QUEUE', 'translations'),         // give it its own Horizon supervisor
        'rate_per_minute' => env('PROSETTA_AI_RATE', 60),
    ],

    'locales' => [
        'source' => LonelyLights\Prosetta\Locales\DatabaseLocaleSource::class, // reads prosetta_locales
        'fallback' => ['en', 'es', 'ar', 'zh-CN'],   // ActiveLocales::fallback() reads this (see §3)
    ],

    'models' => [
        'locale' => LonelyLights\Prosetta\Models\Locale::class,
        'file' => LonelyLights\Prosetta\Models\TranslationFile::class,
        'key' => LonelyLights\Prosetta\Models\TranslationKey::class,
        'translation' => LonelyLights\Prosetta\Models\Translation::class,
        'review' => LonelyLights\Prosetta\Models\TranslationReview::class,
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

**Where the canonical keys live:** `lang/en/*.php`, `lang/en.json` and `app/Modules/<Module>/Lang/en/*.php`. Edit those by hand as today. A module key is referenced as `identity::onboarding.toast.accessCode.inUse`, a root key as `auth.failed`, and a JSON key as `json:Save changes`.

## 6. Contracts Undaunted implements

**`LonelyLights\Prosetta\Contracts\TranslationDriver`** (required for AI):

```php
interface TranslationDriver {
    public function translate(TranslationBatch $batch): TranslationBatchResult;
}
```

The DTOs (namespace `LonelyLights\Prosetta\Data`, all `final readonly`, no Eloquent):

```php
TranslationBatch(string $sourceLocale, LocaleDescriptor $target, ?string $variantOf, ?string $model,
                 array $items /* list<TranslationItem> */, array $feedback = [] /* item id => list<string> problems */)
TranslationItem(string $id, string $keyRef, string $source, ?string $context = null, ?int $maxLength = null,
                array $placeholders = [], ?string $previous = null)
TranslationBatchResult(array $values /* item id => translated string */, string $provider, string $model,
                       int $inputTokens = 0, int $outputTokens = 0, ?string $invocationId = null)
LocaleDescriptor(string $code, string $englishName, string $nativeName, ?string $script = null, bool $rtl = false)
```

- `variantOf` is set for regional variants (`en_GB` of `en`): adapt spelling and usage only.
- `feedback` is non-empty only on the automatic retry.
- Return a value for every item id. A missing id is reported as `failed`.

**`LonelyLights\Prosetta\Contracts\LocaleSource`** is **optional**. The default `DatabaseLocaleSource` already reads Undaunted's `prosetta_locales`, which replaces the old hard-coded list. Implement it only if locales should come from elsewhere:

```php
interface LocaleSource {
    public function source(): string;
    public function targets(): array;   // list<LocaleDescriptor>, excluding the source
    public function find(string $code): ?LocaleDescriptor;
}
```

**Authorization** is a hook, not an interface. See §8, step 3.

**Minimal laravel/ai adapter** (`config/ai.php`, default provider `openai`). Tokens reach your `ai_usage` ledger automatically, because `RecordAiUsage` listens to `AgentPrompted`. Prosetta also stores provider, model, apportioned tokens and `ai_invocation_id` on each translation, and that id joins to `ai_usage.invocation_id`.

```php
// app/Ai/Agents/Translator.php
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
// app/Services/Translation/LaravelAiTranslationDriver.php
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

This follows the `NameReviewer` pattern (`Promptable`, structured output). Add the same fail-soft logging as `AgentNameModerator` if you like. A thrown exception fails that queued chunk, and the retry is bounded by `retryUntil()` (2 hours).

## 7. Commands and services

**Commands** (run from the Undaunted root):

| Command | What it does | Example |
|---|---|---|
| `prosetta:install` | Publishes the config, plus the four workflow migrations. Publishes the locales migration only if the table is missing. | Not needed: Undaunted hand-writes its migrations (§8, step 1). |
| `prosetta:sync [--namespace=*] [--check]` | Reads the `en` files into keys, marks stale and obsolete keys, and imports target files. `--check` changes nothing and exits 1 if anything is missing, stale, unreviewed or broken. | `php artisan prosetta:sync`, then `php artisan prosetta:sync --namespace=identity --check` |
| `prosetta:translate [--locale=*] [--namespace=*] [--key=*] [--force] [--sync]` | Drafts missing and stale keys through your driver. Queued unless `--sync`. | `php artisan prosetta:translate --locale=es --namespace=identity --sync` |
| `prosetta:review {locale} [--approve-clean] [--namespace=] [--limit=20]` | Lists the review queue, or approves every current candidate with no blocking issues. | `php artisan prosetta:review es --namespace=identity`, then `php artisan prosetta:review es --approve-clean --namespace=identity` |
| `prosetta:export [--locale=*] [--namespace=*] [--include-drafts] [--dry-run] [--force]` | Writes target files. Exits 1 on conflicts unless `--force`. Incomplete lists are left out; files whose source group is gone are left untouched and reported. | `php artisan prosetta:export --locale=es --namespace=identity --dry-run` |
| `prosetta:rename {from} {to}` | Carries translations to a key you renamed in the `en` file (sync first). | `php artisan prosetta:rename identity::onboarding.toast.accessCode.inUse identity::onboarding.toast.accessCode.heldElsewhere` |
| `prosetta:stats [--locale=]` | Progress per locale and namespace. | `php artisan prosetta:stats --locale=es` |

**Services:** the `LonelyLights\Prosetta\Facades\Prosetta` facade over `LonelyLights\Prosetta\ProsettaManager`, for Bridge controllers. `$by` is the acting user, and `null` means the system.

```php
sync(?array $namespaces = null, bool $check = false, ?Authenticatable $by = null): SyncReport
translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true, ?Authenticatable $by = null): Batch|TranslateReport
reviewQueue(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator   // of ReviewItem
missing(string $locale, array $filters = [], int $perPage = 50): LengthAwarePaginator       // of MissingItem
write(string $keyRef, string $locale, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation
edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false): Translation
approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null): ApproveReport
approveClean(string $locale, ?string $namespace = null, ?string $group = null, ?Authenticatable $by = null): ApproveReport
reject(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation
export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, ?Authenticatable $by = null, bool $force = false): ExportReport
rename(string $from, string $to, ?Authenticatable $by = null): TranslationKey
stats(?string $locale = null): array   // locale => namespace => [keys, approved, drafts, needs_review, stale, missing, issues, tokens]
lookup(string $keyRef): ?TranslationKey
authorizeUsing(Closure $callback): void
```

**Filters:**
- `reviewQueue` takes `status` (string or list; default draft and needs_review), `stale` (bool; also includes approved-but-stale rows), `namespace`, `group`, `origin`, `issues` (bool) and `search`.
- `missing` takes `namespace`, `group` and `search`.

**Item fields** (both have `toArray()`, ready for Inertia props):
- `ReviewItem`: `translationId`, `keyRef`, `locale`, `source`, `candidate`, `approved`, `status`, `origin`, `stale`, `issues`, `context`, `maxLength`, `aiModel`, `aiProvider`.
- `MissingItem`: `keyRef`, `locale`, `source`, `context`, `maxLength`, `placeholders`.

**Edit-and-approve is atomic:** `edit(..., approve: true)` or `write(..., approve: true)` refuses blocking issues, or a forbidden self-approval, before changing anything.

**Gates:** `prosetta.translate`, `prosetta.review` and `prosetta.manage`, each taking a `?string $locale`. For example, `$user->can('prosetta.review', 'ar')` works in policies, and the result can be shared to Inertia.

**Events** (`LonelyLights\Prosetta\Events`): `KeyAdded`, `KeyChanged`, `KeyObsoleted`, `SyncCompleted`, `TranslationDrafted`, `TranslationSubmitted`, `TranslationApproved`, `TranslationRejected`, `ExportCompleted`.

## 8. Adoption steps for Undaunted

1. **Migrations.** You're pre-alpha, so edit in place.
   - Add four files to `database/migrations/0050_locales/`, copied from the package's `database/migrations/`, following your naming:
     - `0050_01_01_000200_create_prosetta_files_table.php`
     - `…000300_create_prosetta_keys_table.php`
     - `…000400_create_prosetta_translations_table.php`
     - `…000500_create_prosetta_reviews_table.php`
   - They use `LonelyLights\Prosetta\Support\Settings::table()` for names and plain `string` reviewer ids (UUID-safe).
   - The existing `000100` locales migration already matches.
   - Then run `php artisan migrate:fresh --seed` and `php artisan bridge:crew`.
2. **Config.**
   - Replace `config/prosetta.php` with §5.
   - Apply the two `prosetta.locales.fallback` edits (`ActiveLocales.php:78`, `LocalesSeedTest.php:107`).
   - Add the `PROSETTA_*` keys to `.env.example`: `PROSETTA_AI_MODEL`, `PROSETTA_QUEUE` and `PROSETTA_EXPORT_DRAFTS`.
3. **Bindings.**
   - Create `App\Ai\Agents\Translator` and `App\Services\Translation\LaravelAiTranslationDriver` (§6).
   - Either set `ai.driver` in config, or bind it in `AppServiceProvider::register()`: `$this->app->bind(TranslationDriver::class, LaravelAiTranslationDriver::class)`.
   - In `AppServiceProvider::boot()`, register the access hook:
     ```php
     Prosetta::authorizeUsing(fn (User $user, Ability $ability, ?string $locale): bool => match ($ability) {
         Ability::Manage => $user->can('translations.manage'),
         default => $user->can("translations.{$ability->value}.$locale"),
     });
     ```
     `Ability` is `LonelyLights\Prosetta\Enums\Ability`, and `Review` implies `Translate` for the same locale.
   - Seed the permissions (`translations.manage`, plus `translations.translate.{code}` and `translations.review.{code}` for each target locale). Per-locale names don't fit the `PermissionName` enum, so seed them as strings from `Locale::query()->targets()`.
4. **Code changes at call sites** (§3):
   - `AppServiceProvider`, `MakeDefaultController`, `ReorderController` and `LocalesSeeder`: drop `Cache::forget('activeLocales')`.
   - `ActiveLocales::fallback()`: read `prosetta.locales.fallback`.
5. **Blade routes.** Nothing to turn off: the package has no routes. Remove `routes` / `PROSETTA_ROUTES` from config and `.env` (the new config doesn't have them).
6. **Queue.**
   - Add a Horizon supervisor for the `translations` queue, with a small fixed process count. The `job_batches` table already exists.
   - The job sets `retryUntil()` to 2 hours, a 300-second timeout, and an overlap lock that expires after 10 minutes, so it's fine under `tries => 3`.
7. **Tests.**
   - Keep `tests/Feature/Auth/LocaleCoverageTest.php` as is. It checks that the files agree across locales, and Prosetta's export only writes keys that exist in `en`. Treat it as the file-level guard: if an `en` key is added and nothing is approved (or drafted, with drafts exported) for a locale, it fails until that locale is translated. That's intended.
   - Add a CI step `php artisan prosetta:sync --check`. Note it counts **every** target locale, `en_GB`/`en_US` included (§10), so decide on the variants before gating CI on it.
   - Add feature tests for your adapter using `LonelyLights\Prosetta\Testing\FakeTranslationDriver`. Bind it with `app()->instance(TranslationDriver::class, new FakeTranslationDriver)`. It echoes each value with ` [locale]` appended, and has `dropPlaceholders()`, `recasePlaceholders()`, `fixOnRetry()` and `omitValues()` modes.
8. **First run:** `composer update lonely-lights/prosetta`, then `php artisan prosetta:sync`, then `php artisan prosetta:stats`. See §9 for the flow.
9. **Bridge review pages.** These are Undaunted's job, built on `reviewQueue()`, `missing()`, `write()`, `edit()`, `approve()` and `reject()`. Point the Locales page's "Translations" button (`Index.tsx:253`) there instead of `/prosetta`.

## 9. Worked example: `identity::onboarding.toast.accessCode.*`

**Canonical source.** `app/Modules/Identity/Lang/en/onboarding.php` holds `toast.accessCode.capReached` ("Registration has a :minutes-minute limit, so the access code was released. Enter it again to start over."), `.inUse` and `.timedOut`. You wrote the `es`, `ar` and `zh-CN` versions by hand.

**1. Sync.**
```
php artisan prosetta:sync --namespace=identity
```
- Creates file `identity` / `onboarding` and one key per string. `capReached` gets `placeholders = [":minutes"]` and a sha256 `source_hash`.
- Your hand-written `es`, `ar` and `zh-CN` values import as `origin=imported, status=approved`, with `exported_hash` = the file value's hash, **after** the placeholder guard passes them. They all keep `:minutes`, so they do.

**2. One AI translation into `es`.** The keys are already translated, so force a fresh draft of just `capReached`:
```
php artisan prosetta:translate --locale=es --key=identity::onboarding.toast.accessCode.capReached --force --sync
```
- The runner sends one `TranslationItem`: id, keyRef, source, `placeholders [":minutes"]`, and `previous` = your current Spanish.
- Suppose the model returns "El registro tiene un límite de :minutes minutos, así que el código de acceso se liberó. Introdúcelo de nuevo para empezar otra vez." The guard finds `:minutes`, with the same case, and no plural or HTML to check, so there are no issues.
- It's saved as the **candidate**: `status=draft`, `origin=ai`, `ai_provider` / `ai_model`, apportioned `input_tokens` / `output_tokens`, and `ai_invocation_id`. A review row `submitted` is written by the system.
- Your approved Spanish is **untouched** and still live.
- If the model had dropped `:minutes`, the guard would record `placeholder_missing`, retry once with "Placeholder :minutes is missing." as feedback, and if it still failed, save a draft that can't be approved or exported.

**3. Review.**
```
php artisan prosetta:review es --namespace=identity                     # lists the draft
php artisan prosetta:review es --namespace=identity --approve-clean     # approves clean current candidates
```
In the Bridge, that's `Prosetta::approve($translationId, $request->user())`, or `edit(..., approve: true)` to tweak and approve in one atomic step. Approval copies the candidate into `approved_value` / `approved_source_hash`, sets `reviewed_by` (your UUID, stored as a string), and logs `approved`.

**4. Export.**
```
php artisan prosetta:export --locale=es --namespace=identity --dry-run
php artisan prosetta:export --locale=es --namespace=identity
```
- **Conflict check first:** the file on disk must hold only values Prosetta knows. Your hand-written file was imported in step 1, so it passes.
- It then rewrites `app/Modules/Identity/Lang/es/onboarding.php` with:
  - a generated header ("Generated by Prosetta from identity::en/onboarding.php. …");
  - nested short arrays in **`en`'s key order**;
  - the approved values, with `'capReached' => 'El registro tiene un límite de :minutes minutos, …'`, so the placeholder is intact.
- The write is atomic (temp file, then rename), and `exported_hash` is updated.
- The next `prosetta:sync` sees the file matches and reports no hand edits.
- **The first export replaces the `# Central Core:` comments** in that `es` file with the header. Values and order are unchanged; comment copying is a planned follow-up.

`LocaleCoverageTest` still passes afterwards, because keys and placeholders are unchanged.

## 10. Open questions and known gaps

- **Not yet run against Undaunted's real files.** Every flow here is tested against a fixture shaped like Undaunted, which includes these exact three strings, but not against the full `lang/` and `app/Modules/*/Lang` trees. Expect the first real sync to surface edge cases; start with `--namespace=identity`.
- **Variants cover every namespace.** `translated=true` makes `en_GB` and `en_US` targets for **all** namespaces, not just Showcase, so everywhere else they show as missing and `sync --check` fails. With US spelling now canonical, `en_US` duplicates `en`. **Recommendation:** set `en_US` to `translated=false` and retire `app/Modules/Showcase/Lang/en_US`, then keep `en_GB` and let the AI fill it (it returns the source unchanged when nothing differs).
- **US spelling pass (decided).** `en` should use US spelling throughout. Today's canonical `en` files still contain British forms across several modules: `catalogue` (8), `organisation(s)` / `Organisation(s)`, `cancelled`, `centre` and `colour`.
  - Convert the **values**, and run sync so their translations show as stale; `en_GB` then carries the British forms.
  - Some British spellings are also in **key and file names** (`organisationRequests`, `organisationName`, `Identity/Lang/*/organisations.php`). Renaming those is optional, touches every `__()` call that uses them, and needs `prosetta:rename` after each rename, so do values first and treat key names as a separate decision.
- **The Bridge review UI doesn't exist yet**, and the `/prosetta` link is dead until it does (§3).
- **Hand-written target-file comments are lost on first export** (see §9).
- **No spatie dependency (decided).** Eloquent content translation will use Prosetta's own storage when it's built. It isn't built yet.
- **Other post-MVP items not built:** the report-a-bad-translation backend (for your select-text menu), production review with a pull back to git, copying `en` comments into target files, and the AI model and cost catalogue.
- **Behaviour worth knowing (from the post-review fixes):**
  - A `|` counts as plural forms only when the string also has range markers (`{1}`, `[2,*]`) or `:count`. `"Home | :app"` is plain text, and only warns if the translation changes the number of pipes.
  - Queued `translate()` fails immediately with `MissingDriverException` when no driver is bound, instead of failing inside every job.
  - `prosetta:sync --check --namespace=identity` counts only that namespace. Outstanding counts each key and locale once.
  - Review and missing searches are case-insensitive, and treat `%` and `_` literally.
  - A PHP list (`steps.0`, `steps.1`, …) is exported whole or not at all, so a half-translated list falls back to English rather than exporting with gaps.
  - When a source group disappears, its target files are left untouched and listed as `orphaned` in the export report and command output. Delete them by hand.
  - A bulk `approve()` authorizes every locale before approving anything.
  - The old `tableNames` key is no longer read. Undaunted's table names are the defaults.
- **Still deferred:**
  - `Stats` loads all rows into PHP. That's fine at Undaunted's scale; SQL aggregates come later if needed.
  - The AI rate limiter is app-wide (`prosetta-ai`, `queue.rate_per_minute`), not per provider, because the provider is only known after a call.
- **`feat/responses-ledger`** carries `a84056a` and `ff19f8a` and isn't pushed. Pushing it is this session's call.
- **The Alexandria copy** (`C:\Websites\alexandria\packages\prosetta`) is now a clean clone at the same commit as `main`, and nothing links to it.
  - `alexandria-legacy/vendor/lonely-lights/prosetta` is a junction to **`C:\Websites\packages\prosetta`**, so it already runs this rebuild.
  - It uses only `Locale`, through `App\Models\Prosetta\Locale`, which is now honoured via `config('prosetta.models.locale')`.
  - It boots on Laravel 13 and reads its 100 locale rows.
- **Publishing** (tag, Packagist, a CI matrix for Laravel 11/12/13) is still to be decided.

## 11. Resilience layer (added 2026-09-23)

Translation runs can now be left unattended: Prosetta backs off individual failures, opens a circuit breaker per provider/model after repeated failures, halts cleanly on a bad key or exhausted quota, suspends the run's scope so nothing is lost, and resumes it automatically once the provider recovers. Token budgets (per run, day and month) stop a run before it overspends. Everything is configured in `config/prosetta.php`; nothing here needs a migration, since all state lives in the cache. Full design: `docs/superpowers/specs/2026-09-23-resilience-layer-design.md`.

**Undaunted's side, from the design's §12:**

- **`LaravelAiTranslationDriver` maps errors:**
  - `RateLimitedException` → `ProviderRateLimited`, with `retryAfter` when laravel/ai exposes it;
  - `ProviderOverloadedException` and `ProviderConnectionException` → `ProviderUnavailable`;
  - `InsufficientCreditsException` → `ProviderQuotaExhausted`;
  - an HTTP 401, 403, 404 or 400 from the provider → `ProviderRejected`;
  - anything else is left to `unknown_errors`.
- **It implements `checkHealth()`** by asking the Translator agent to translate the single word "OK" into Spanish with the configured model. That costs a handful of tokens, is recorded in `ai_usage` like any call, and proves both the key and the model.
- **Listeners** for the resilience events (`CircuitOpened`, `CircuitClosed`, `TranslationHalted`, `TranslationSuspended`, `TranslationResumed`, `BudgetReached`) log to the app log for now. Notifications (mail or the Bridge) come later.
- **The translations worker** needs `queue:restart` on deploy (G4) whenever the driver or translation config changes. That's noted in the package README, not solved by Prosetta itself.

**Config values to use** (everything else stays at the package default):

```php
'resilience' => [
    'halt_hold' => 600,                  // halts test themselves after 10 minutes
    'resume_every' => 10,                // resume suspended work within 10 minutes of recovery
    // everything else: the package defaults
],
'budgets' => ['per_run' => 250_000, 'daily' => 500_000, 'monthly' => 5_000_000],
```

**Scheduling.** `resume_every: 10` only takes effect if Undaunted actually runs Laravel's scheduler. Add `schedule:work` to the `composer dev` script (alongside the existing `serve`/`queue:listen`/`vite` processes) for local development, and confirm the production cron entry (`* * * * * php artisan schedule:run`) is in place before relying on automatic resume there.

**Adoption steps** (in addition to §8 above):

1. Add the `'resilience'` and `'budgets'` config blocks above to `config/prosetta.php`.
2. Implement `checkHealth()` on `LaravelAiTranslationDriver` and map laravel/ai's exceptions to Prosetta's `ProviderException` subclasses, both as described above.
3. Register listeners for the six resilience events (log for now; notifications later).
4. Add `schedule:work` to `composer.json`'s `dev` script.
5. Confirm the production scheduler is running (`schedule:run` on cron), so `prosetta:resume` actually fires every 10 minutes.
6. Note `php artisan queue:restart` as a required deploy step whenever the driver or translation config changes (G4 is now documented, not yet automated).
