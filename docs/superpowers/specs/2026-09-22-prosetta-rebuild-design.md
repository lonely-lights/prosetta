# Prosetta Rebuild — Design

- **Date:** 2026-09-22
- **Branch:** `rebuild/v1` (worktree at `C:\Websites\packages\prosetta-rebuild`; `main` stays live for Undaunted until merge)
- **Status:** Approved in conversation, section by section; this document awaits written review.
- **Primary consumer:** Undaunted (`C:\Websites\undaunted\undaunted-web`, Laravel 13, PHP 8.4, Postgres 18, Inertia v3 + React)

## 1. Goals

1. **Translate with AI models** through a host-supplied driver. Machine output enters as a draft for human review. Placeholders and plural forms survive and are checked. Model, provider and tokens are recorded per translation.
2. **One canonical source of keys:** the source locale's lang files, edited by hand, including namespaced module keys. Other locales follow: missing keys are flagged, stale translations are marked, and nothing a person reviewed is silently overwritten.
3. **Work cleanly with Undaunted:**
   - discover module namespaces;
   - keep root JSON and PHP;
   - support regional variants;
   - expose a headless service API for its Inertia admin;
   - read locales from the host.
4. **Per-language reviewers** from v1, decided by the host's own authorization.

## 2. Decisions

| # | Decision |
|---|---|
| D1 | Blank-slate rebuild in the same repo (`rebuild/v1` worktree). Nothing from `main` carries over except `Models\Locale`, which stays compatible. |
| D2 | **Source-language files are canonical.** Prosetta only *reads* them and only *writes* target locales. The database holds workflow state only. |
| D3 | **Headless.** No Blade UI, routes or controllers. Hosts call services, commands and events. |
| D4 | Locale is translation-only. `active` means offered to members; `translated` means Prosetta maintains it. Language statistics belong to hosts. (Already on `main`: `be8c70d`.) |
| D5 | Export writes **approved values only** by default. Drafts can be included by config or flag. Values with open placeholder issues are never exported. |
| D6 | Regional variants are Locale rows with `translated = true` and `active = false` (Undaunted: `en_GB`, `en_US`). |
| D7 | The host decides authorization through one callback that receives the locale (per-language reviewers). |
| D8 | Work queues are derived from state. Execution uses Laravel's queue (Horizon), not a Prosetta queue table. |
| D9 | Composer: PHP `^8.3`, `illuminate ^11|^12|^13`. (Already on `main`: `74979be`.) |
| D10 | Hard-to-retrofit wiring goes into the MVP even where the feature comes later: namespace/group files, source hashes, approved/candidate split, exported hash, AI provenance, string reviewer ids, key `kind`, and `model` on the batch. |
| D11 | The `TranslationDriver` stays stateless and its DTOs contain no Eloquent models, so a hosted "Prosetta API" driver remains possible (roadmap, §15). |

## 3. Scope

**MVP (this spec's build):**
- UI-string translation end to end: discovery, sync, AI translation, review, export, stats;
- commands and the facade;
- the lean Locale.

**Designed for, built after the MVP (in this order unless reprioritised):**
1. Eloquent content translation via spatie/laravel-translatable (§12).
2. Report-a-bad-translation backend (select text, then report with notes and a suggested correction; key resolved via `lookup()`).
3. Production review with a pull back into dev for export and commit.
4. Copying the source file's comments into target files.
5. AI model and cost catalogue (config array fallback, or a host database table mapped by column).
6. An optional Blade/Inertia UI for other adopters.

**Non-goals:** writing source-locale files; deleting files; managing `lang/vendor` overrides; runtime database translation loading.

## 4. Package shape

- **Name and namespace:** `lonely-lights/prosetta`, `LonelyLights\Prosetta`.
- **Requires:** `php ^8.3`, `illuminate/contracts|database|support|queue|console ^11|^12|^13`.
- **Suggests:** `spatie/laravel-translatable` (content translation, post-MVP).
- **Dev:** Pest, and the orchestra/testbench release matching Laravel 13 (exact constraint pinned in the plan).
- Every model class resolves through `config('prosetta.models.*')`.

**Removed from `main`:**
- the queue table and its stub;
- the `create_locales_table` and `add_prosetta_locale_columns` stubs;
- the statistics `LocaleSeeder`;
- `LangKeyService`, `FileSynchronizer`, `KeyManager`, `TranslationService` and `MarkdownSanitizer`;
- the old `Traits\HasTranslations`;
- the Blade views, routes, controllers and view components;
- the HTMLPurifier dependency;
- the `activeLocales` singleton and cache key, and `Locale::getActiveLocales()`.

## 5. Data model

Reviewer and user ids are `string` columns without a foreign key (int or UUID hosts). Table names are configurable.

### `prosetta_locales` (compatible with Undaunted's existing migration)
| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | Stable key; hosts reference this, not the code |
| `locale_initials` | string(35) unique | Code exactly as the folder is named (`zh-CN`, `en_GB`). **Immutable after create** (an `updating` guard throws) |
| `english_name` | string | Used in AI prompts and labels |
| `native_name` | string | Display |
| `script` | string nullable | AI hint |
| `rtl` | bool | Review UI direction |
| `active` | bool, indexed | Offered to members |
| `translated` | bool, indexed | Maintained by Prosetta even when not offered |
| `is_default` | bool, indexed | The host's visitor default; **not** the source locale |
| `sort_order` | int | Display |
| timestamps | | |

The model keeps the scopes `active()`, `default()` and `ordered()`, the `code` accessor, `getDefault()`, `findByCode()`, `setAsDefault()`, `toggleActive()`, `getDisplayName()`, `isRtl()` and `getDirection()`. It adds a `targets()` scope (`active OR translated`). It clears its own caches in `booted()`. It is **not** `final`. Recommended host extension: a host table plus `Locale::resolveRelationUsing(...)`; subclassing through `models.locale` is also supported.

### `prosetta_files`
`id` · `namespace` string (`'*'` = root) · `group` string (`'onboarding'`, `'admin/messages'`, `'*'` = JSON) · `format` (`php`|`json`) · timestamps · unique(`namespace`, `group`).

### `prosetta_keys`
`id` · `file_id` fk · `kind` (`file`; `content` post-MVP) · `key` (dot path within the group; whole string for JSON) · `source_value` text · `source_hash` char(64) (sha256 of the exact value) · `placeholders` json · `context` text nullable · `max_length` int nullable · `obsolete_at` timestamp nullable · timestamps · unique(`file_id`, `key`).

### `prosetta_translations`
| Column | Notes |
|---|---|
| `id`, `key_id` fk, `locale` string(35) | unique(`key_id`, `locale`) |
| `value` text, `source_hash` | **Candidate:** the latest draft or edit, and the source hash it was made from |
| `approved_value` text nullable, `approved_source_hash` nullable | **Live:** what users see; written by approval |
| `status` | `draft` · `needs_review` · `approved` · `rejected` (the candidate's status) |
| `origin` | `manual` · `ai` · `imported` |
| `issues` json nullable | Placeholder, plural and HTML problems from `PlaceholderGuard` |
| `ai_provider`, `ai_model`, `input_tokens`, `output_tokens`, `ai_invocation_id` | Provenance. Tokens are the apportioned share of the call; exact totals join via `ai_invocation_id` (Undaunted: `ai_usage.invocation_id`) |
| `exported_hash` nullable | Hash of the value last written to the target file (detects hand edits) |
| `reviewed_by` string nullable, `reviewed_at` | |
| timestamps | Indexes: (`locale`, `status`) |

### `prosetta_reviews`
`id` · `translation_id` fk · `reviewer_id` string nullable (null = system or console) · `action` (`submitted`|`approved`|`rejected`|`edited`|`imported`) · `previous_value` · `new_value` · `notes` · timestamps.

### Derived states (no stored queue)
- **Target locales:** `Locale::targets()` minus the source locale.
- **Missing:** a target locale has no `approved_value` and no candidate other than a `rejected` one (including when there is no translation row at all). A rejected candidate therefore returns the key to the work list.
- **Stale (live):** `approved_source_hash ≠ key.source_hash`. **Stale (candidate):** `source_hash ≠ key.source_hash`.
- **Awaiting review:** `status ∈ {draft, needs_review}`.
- **Obsolete:** `obsolete_at` is not null. Excluded from export and from work lists; history kept.

## 6. Configuration (`config/prosetta.php`)

```php
return [
    'source_locale' => env('PROSETTA_SOURCE_LOCALE', 'en'),

    // Lang roots → namespaces. '*' root = lang_path(). Others are discovered from
    // app('translation.loader')->namespaces() (every loadTranslationsFrom() hint).
    'namespaces' => [
        'discover' => true,
        'include'  => ['*'],      // namespace names or '*'
        'exclude'  => [],
    ],
    'paths' => [],                // explicit overrides: 'identity' => app_path('Modules/Identity/Lang')
    'exclude_paths' => ['lang/vendor', 'vendor'],   // globs or absolute paths (relative ones resolve against base_path()); skipped by sync, refused by export

    'export' => [
        'include_drafts' => env('PROSETTA_EXPORT_DRAFTS', false),
    ],

    'review' => [
        'allow_self_approval' => true,
    ],

    'ai' => [
        'driver' => null,         // class-string<TranslationDriver>; or bind the contract in a provider
        'model'  => env('PROSETTA_AI_MODEL'),
        'models' => [],           // per-locale overrides: 'ar' => 'gpt-…'
        'batch'  => 25,           // keys per AI call
        'retries_on_issues' => 1,
    ],

    'queue' => [
        'connection'      => env('PROSETTA_QUEUE_CONNECTION'),
        'name'            => env('PROSETTA_QUEUE', 'translations'),
        'rate_per_minute' => env('PROSETTA_AI_RATE', 60),
    ],

    'locales' => [
        'source' => \LonelyLights\Prosetta\Locales\DatabaseLocaleSource::class,
        'fallback' => ['en'],     // used only by ConfigLocaleSource
    ],

    'models' => [
        'locale'      => \LonelyLights\Prosetta\Models\Locale::class,
        'file'        => \LonelyLights\Prosetta\Models\TranslationFile::class,
        'key'         => \LonelyLights\Prosetta\Models\TranslationKey::class,
        'translation' => \LonelyLights\Prosetta\Models\Translation::class,
        'review'      => \LonelyLights\Prosetta\Models\TranslationReview::class,
    ],

    'table_names' => [
        'locales' => 'prosetta_locales', 'files' => 'prosetta_files', 'keys' => 'prosetta_keys',
        'translations' => 'prosetta_translations', 'reviews' => 'prosetta_reviews',
    ],

    'log_channel' => null,        // null = app default
];
```

Compatibility: `tableNames.locales` (the old camelCase key) is read as a fallback for `table_names.locales`, so Undaunted's existing migration keeps working until its config is replaced.

## 7. Sync (`Prosetta::sync()`, `prosetta:sync`)

**1. Discover roots.**
- `'*'` gives `lang/{locale}/**/*.php` (nested folders become slash groups) and `lang/{locale}.json` (group `'*'`).
- Each namespace comes from the loader hints plus `paths`, filtered by include/exclude.
- Anything matching `exclude_paths` is skipped.

**2. Read the source locale.**
- PHP files are loaded like Laravel loads them and flattened to dot keys. Non-string leaves are reported and skipped.
- JSON keys are kept whole.

**3. Per source key:** upsert the file and key; set `source_value`, `source_hash` and `placeholders`.
- New key: created (now missing everywhere).
- Changed value: new hash (translations become stale by derivation).
- Absent key: `obsolete_at = now`.
- Key that reappears: `obsolete_at = null`.

**4. Read target locales** (every target, from the same roots).
- **First import** (no translation row): `origin=imported`, `status=approved`. Both value pairs are set to the file value with the current hash. `exported_hash` is set to its hash.
- **Later syncs:** if the file value's hash differs from `exported_hash`, someone edited the file by hand. It becomes the new candidate (`origin=manual`, `status=needs_review`), with a review row (`imported`) that holds the previous value. The approved value is never replaced silently.

**5. Result.** Returns a `SyncReport` (counts per namespace and locale: new, changed, obsolete, missing, stale, issues, hand edits). Fires `KeyAdded`, `KeyChanged`, `KeyObsoleted` and `SyncCompleted`.

**6. Check mode.** `--check` / `sync(check: true)` writes nothing and exits 1 when anything is missing, stale, awaiting review or has issues (a CI gate).

**Rename** (`Prosetta::rename($from, $to)`, `prosetta:rename {from} {to}`): run after renaming the key in the source file. It moves the translations and reviews from the old key to the new one, then deletes the empty old key. It fails if the target key already has translations.

**Key references** use Laravel's format:
- `identity::onboarding.toast.accessCode.inUse`
- `auth.failed` (root)
- `json:Some sentence.` (JSON group)

`KeyRef::parse()` / `KeyRef::toString()` convert between the two. The group ends at the first `.` (groups may contain `/`, never `.`).

## 8. AI translation (`Prosetta::translate()`, `prosetta:translate`)

**Selection.** Keys that are missing or stale per target locale, narrowed by locales, namespaces or keys. `--force` includes current keys too.

**Execution.** Queued by default:
- `TranslateBatch` jobs, one per (locale, file), in chunks of `ai.batch`;
- dispatched as a `Bus::batch` (progress visible to hosts);
- on `queue.connection` / `queue.name`;
- with `RateLimited` middleware per provider and `WithoutOverlapping` per (locale, file);
- `--sync` / `queue: false` runs inline.

**Idempotence.** Before calling the driver, a job reloads its keys and drops any whose hash changed, or that gained a current candidate or approved value since dispatch.

**Driver.** The job builds a `TranslationBatch` and calls `TranslationDriver::translate()`.

**Guard.** It runs `PlaceholderGuard` on each value. On violation it retries once (`ai.retries_on_issues`) with the violations stated in `TranslationBatch::$feedback`. A still-failing value is saved as a `draft` with `issues`.

**Persist.** Candidate `value` and `source_hash`, `status=draft`, `origin=ai`, provenance (tokens apportioned by source length), and a review row (`submitted`, reviewer null). Fires `TranslationDrafted`.

**Prompt hints the driver receives** (the host decides how to use them):
- target `english_name`, `script`, `rtl`;
- `context` and `max_length`;
- the previous approved value when re-translating a stale key;
- a `variant_of` code for regional variants (`en_GB` → `en`), meaning "adapt spelling and usage; return the source unchanged when nothing differs".

### PlaceholderGuard
- **Placeholders:** `:[A-Za-z_]+` tokens must match the source as a multiset, **case-sensitive** (`:name`, `:Name` and `:NAME` behave differently in Laravel).
- **Plurals:**
  - explicit ranges (`{0}`, `{1}`, `[2,*]`) must be kept;
  - the segment count must be ≥ 1 and must not exceed the target language's plural forms;
  - a count that differs from the source is reported as a warning issue, not an error (Arabic may need more segments);
  - placeholders are checked per segment.
- **HTML:** tag names and their order must be preserved (checked when the source contains tags).
- **Output:** a list of `Issue` value objects (`code`, `severity` error|warning, `message`). Only `error` issues block approval and export.

## 9. Review and authorization

**Abilities** (`enum Ability: string`): `Translate`, `Review` (implies Translate), `Manage` (locale-agnostic).

**Hook:**
```php
Prosetta::authorizeUsing(fn (Authenticatable $user, Ability $ability, ?string $locale): bool => …);
```
- Registered Gates: `prosetta.translate`, `prosetta.review`, `prosetta.manage` (argument: `?string $locale`).
- With no hook: allow when `app()->environment('local')`, deny otherwise.
- Service methods take `?Authenticatable $by`; `null` means system or console and is always allowed.

| Operation | Ability | Effect |
|---|---|---|
| `edit($id, $value, $by)` | Translate(locale) | Candidate becomes `value`; `origin=manual`, `status=needs_review`; guard runs; review row `edited` |
| `approve($ids, $by)` | Review(locale) | Refused if the candidate has error issues. `approved_value`/`approved_source_hash` ← candidate; `status=approved`; `reviewed_*`; review row; `TranslationApproved` |
| `edit(..., approve: true)` | Review(locale) | Edit, then approve, atomically |
| `reject($id, $by)` | Review(locale) | `status=rejected`; approved pair untouched; review row; `TranslationRejected` |
| Bulk approve | Review(locale) | `approve()` over all clean candidates for a locale (optionally a file) |
| `sync`, `export`, `rename` | Manage | |

**Self-approval:** when `review.allow_self_approval` is false, `approve()` refuses a candidate whose last edit or submission was by the same user.

**`reviewQueue(string $locale, array $filters = [], int $perPage = 50)`** returns a paginator of `ReviewItem`:
- the key ref, source value, candidate, approved value and status;
- flags: stale, issues;
- provenance and context.

Filters: namespace, group, status, stale, has-issues, origin, search.

## 10. Export (`Prosetta::export()`, `prosetta:export`)

**Values.** For each non-obsolete key and target locale:
- **Approved only (default):** the value is `approved_value`.
- **Drafts included** (config or flag): the candidate `value` is used when its status is `draft`, `needs_review` or `approved` and it has no error issues. Otherwise `approved_value` is used.
- **Neither available:** the key is omitted, so Laravel falls back to the source.

**Paths:**
- root → `lang/{locale}/{group}.php`
- JSON → `lang/{locale}.json`
- namespace → `{hint path}/{locale}/{group}.php`

It never writes the source locale, a path outside the discovered roots, or a path matching `exclude_paths`.

**Format:**
- PHP: `<?php` plus a header comment ("Generated by Prosetta from {source}/{group}. Edit the source file or use Prosetta; hand edits here are imported for review on the next sync."), then `return [...]` with nested arrays **in source-file key order**, short syntax, 4-space indentation, single-quoted strings with escaping, and readable Unicode.
- JSON: pretty-printed, `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, in source key order.

**Safety:**
- atomic write (temporary file in the same directory, then rename);
- unchanged content is not rewritten;
- files are never deleted;
- `dryRun` reports what would change;
- `exported_hash` is updated per written value.

**Result.** Returns an `ExportReport` (files written or unchanged, keys per file). Fires `ExportCompleted`.

**Known one-time effect:** existing hand-written target files lose their comments on the first export (comment-copying is post-MVP).

## 11. Contracts, public API, commands, events

### Contracts (`LonelyLights\Prosetta\Contracts`)
```php
interface TranslationDriver {
    public function translate(TranslationBatch $batch): TranslationBatchResult;
}

interface LocaleSource {
    public function source(): string;
    /** @return list<LocaleDescriptor> */
    public function targets(): array;
    public function find(string $code): ?LocaleDescriptor;
}
```

**DTOs** (readonly, serialisable, no Eloquent):
- `TranslationBatch`: `sourceLocale`, `target: LocaleDescriptor`, `?variantOf`, `?model`, `items: list<TranslationItem>`, `feedback: array`.
- `TranslationItem`: `id`, `keyRef`, `source`, `?context`, `?maxLength`, `placeholders`, `?previous`.
- `TranslationBatchResult`: `values: array<id,string>`, `provider`, `model`, `inputTokens`, `outputTokens`, `?invocationId`.
- `LocaleDescriptor`: `code`, `englishName`, `nativeName`, `?script`, `rtl`.

**Implementations:** `DatabaseLocaleSource` (default) and `ConfigLocaleSource`. No `TranslationDriver` ships; `FakeTranslationDriver` lives in the test support namespace and is usable by hosts in their tests.

### Facade `LonelyLights\Prosetta\Facades\Prosetta` → `ProsettaManager`
| Method | Returns |
|---|---|
| `sync(?array $namespaces = null, bool $check = false, ?Authenticatable $by = null)` | `SyncReport` |
| `translate(array $locales = [], array $namespaces = [], array $keys = [], bool $force = false, bool $queue = true, ?Authenticatable $by = null)` | `Illuminate\Bus\Batch` or `TranslateReport` |
| `reviewQueue(string $locale, array $filters = [], int $perPage = 50)` | `LengthAwarePaginator<ReviewItem>` |
| `edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false)` | `Translation` |
| `approve(int\|array $translationIds, ?Authenticatable $by, ?string $notes = null)` | `ApproveReport` |
| `reject(int $translationId, ?Authenticatable $by, ?string $notes = null)` | `Translation` |
| `export(array $locales = [], array $namespaces = [], ?bool $includeDrafts = null, bool $dryRun = false, ?Authenticatable $by = null)` | `ExportReport` |
| `rename(string $from, string $to, ?Authenticatable $by = null)` | `TranslationKey` |
| `stats(?string $locale = null)` | `Stats` (per locale × namespace: keys, approved, drafts, needs_review, stale, missing, issues, tokens) |
| `lookup(string $keyRef)` | `?TranslationKey` with translations |
| `authorizeUsing(Closure $callback)` | `void` |

### Commands
- `prosetta:install`
- `prosetta:sync [--namespace=*] [--check]`
- `prosetta:translate [--locale=*] [--namespace=*] [--key=*] [--force] [--sync]`
- `prosetta:review {locale} [--approve-clean]`
- `prosetta:export [--locale=*] [--namespace=*] [--include-drafts] [--dry-run]`
- `prosetta:rename {from} {to}`
- `prosetta:stats [--locale=]`

### Events (`LonelyLights\Prosetta\Events`)
`KeyAdded`, `KeyChanged`, `KeyObsoleted`, `SyncCompleted`, `TranslationDrafted`, `TranslationSubmitted`, `TranslationApproved`, `TranslationRejected`, `ExportCompleted`.

## 12. Post-MVP: Eloquent content (designed now)

- **Storage** is spatie/laravel-translatable JSON columns; only **approved** values are written there, through `setTranslation()` (guarded so Prosetta ignores its own writes).
- **Prosetta tracks each field** as a `prosetta_keys` row with `kind = content`, pointing at the model (morph type, id, field). Reviews, AI, provenance, stats and permissions are shared with UI strings.
- **Host model:** `use HasTranslations, ReviewsTranslations;` plus `public array $translatable = [...]` (the single whitelist). Optional `translationContext(): array` (context and max_length per field) and `shouldTranslate(): bool`.
- **After a source-locale change is committed,** only the changed whitelisted fields are re-hashed.
- **`queueTranslation(array $fields = [], array $locales = [])`** throws on a field outside the whitelist. It dispatches with `ShouldBeUnique` per (model, field) so rapid edits coalesce.
- **Warning:** a non-source locale set outside Prosetta's approval path triggers a warning (spatie writes plain assignments to the current app locale).
- **Open decision:** whether spatie is a Composer `suggest` (recommended) or a hard `require`.

## 13. Undaunted adoption (summary; the full hand-off report is written at the end)

1. Migrations: add the four new tables to `database/migrations/0050_locales/`. The locales table is done (`a84056a`: `translated`, code width 35, `en_GB`/`en_US` rows).
2. Config: replace `config/prosetta.php` with §6. Remove `locales`, `visibility`, affixation, `routes`, `stack` and the other old keys.
3. `AppServiceProvider`:
   - bind `TranslationDriver` to `App\Services\Translation\LaravelAiTranslationDriver` (a laravel/ai agent with structured output; `RecordAiUsage` logs every call);
   - add the `Prosetta::authorizeUsing()` hook over spatie permissions;
   - remove the `Cache::forget('activeLocales')` lines (also in the Bridge `MakeDefaultController` and `ReorderController`).
4. Permissions: `translations.manage`, plus `translations.translate.{code}` and `translations.review.{code}` for each target locale.
5. Horizon: a supervisor for the `translations` queue.
6. Bridge: no change is needed for immutable codes. `UpdateLocaleRequest` never accepts `locale_initials`; `UpdateController::FIELDS` only snapshots it for the audit.
7. Tests: keep `LocaleCoverageTest` as the file-level guard, and add `php artisan prosetta:sync --check` to CI.

## 14. Testing (package)

- **Tooling:** Pest with orchestra/testbench (the Laravel 13 line); SQLite in memory. The schema avoids engine-specific types. Undaunted's suite covers Postgres.
- **Fixture app** under `tests/Fixtures/app`, mirroring Undaunted:
  - root nested PHP and root JSON;
  - a module namespace registered with `loadTranslationsFrom`;
  - an `en_GB` variant;
  - Identity's real `toast.accessCode.inUse`, `.timedOut` and `.capReached` (`:minutes`);
  - a plural string, an HTML string, and a decoy folder under `exclude_paths`.
- **`FakeTranslationDriver`:** deterministic output, plus a misbehaving mode (drops or recases a placeholder) for the guard, retry and issues.
- **Required tests:**
  - discovery and exclusions;
  - sync outcomes: new, changed, stale, obsolete, reappear, first import, hand edit;
  - rename;
  - the guard matrix;
  - the translate job: batching, idempotence when the hash changed, provenance;
  - review transitions and self-approval;
  - authorization per ability and locale, and the default with no hook;
  - export golden files (PHP and JSON), key order, drafts on and off, issues excluded, source never written, excluded paths refused, atomic and unchanged-skip behaviour;
  - round trip (export → sync = no changes);
  - Locale code immutability and `targets()`.

## 15. Roadmap (after shipping, not scoped)

- A hosted Prosetta API (user registration, metered API keys) offered as another `TranslationDriver`. Enabled by D11.
- The post-MVP items in §3.

## 16. Estimates

**MVP: about 22–28 hours** of focused build time (3–5 sessions), plus writing the plan. The main uncertainty is edge cases in Undaunted's real files.

**Post-MVP:**

| Item | Estimate |
|---|---|
| Content (spatie) | 4–6 h |
| Report backend | 3–4 h |
| Production pull | 6–8 h |
| Comment copying | about 4 h |
| Model catalogue | about 3 h |

## 16a. Amendments made while writing the plan

These refine the approved design and don't change behaviour anyone agreed to:

1. **Key uniqueness:** `prosetta_keys` gains `key_hash` (sha256 of `key`), and uniqueness is `unique(file_id, key_hash)`. `key` becomes `text`, because JSON keys are whole sentences and can exceed index length limits.
2. **Default `exclude_paths` is `['lang/vendor', 'vendor']`.** Without `vendor`, discovery would pick up third-party packages' `loadTranslationsFrom()` namespaces and export into `vendor/`.
3. **Locale caching:** the Locale model keeps no cache, so there's nothing for `booted()` to clear. `DatabaseLocaleSource` queries each time; there are few rows and it's safe in long-lived queue workers.
4. **Imported values with blocking issues:** a first-import target value with a blocking guard issue (for example a missing `:placeholder`) imports as `needs_review` with no `approved_value`, so it counts as outstanding and is never exported as approved.

## 16b. Decisions after the MVP shipped (2026-09-22)

1. **No spatie/laravel-translatable dependency.** Eloquent content translation (§12), when built, stores its values in Prosetta's own tables rather than JSON columns. The `suggest` entry is dropped. An optional spatie adapter can be revisited only if a host needs one.
2. **Undaunted's canonical `en` uses US spelling.** `en_GB` carries British spelling; `en_US` duplicates the source and should be retired in Undaunted.
3. **The Alexandria copy** (`C:\Websites\alexandria\packages\prosetta`) is updated to match this repository.
4. **Publishing timing** is to be decided.

## 17. Risks and open questions

- **Mixed English spelling in the canonical source.** Showcase's `en` is US spelling while Identity's is British ("organisations"). Pick one for `en` eventually; the variants handle the other.
- **Comments in target files.** Undaunted's hand-written target files lose their comments on the first export.
- **Alexandria copy** (`C:\Websites\alexandria\packages\prosetta`): it isn't identical (composer.json differs, stray `tmpclaude-*` files), and `alexandria-legacy` subclasses Locale and has its own Prosetta migrations. Whether to delete it, re-sync it or point it at this repo is undecided.
- **`cn-ZH`** wasn't found anywhere (repo, history, Alexandria, Undaunted); treated as already fixed.
- **Spatie dependency:** suggest or require (§12).
- **Locale codes become immutable,** which changes Bridge editing (§13.6).
- **Laravel 13 testbench:** its availability and constraint are confirmed at plan time.
