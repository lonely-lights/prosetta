# Background Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Site translations keep themselves current. Auto-translate languages get new strings drafted by a scheduled cycle. English edits update every existing translation minimally. What passes every check is approved and exported. Spending, the heartbeat and failures are durable and reported.

**Architecture:**
- **Language settings:** the locale row gains `auto_translate`, `style_note` and `glossary`. The locale descriptor carries them to drivers, and a `GlossaryGuard` checks the results.
- **Edits:** each translation stores the English it was approved against (`approved_source_value`). `SourceChange` classifies an edit: a cosmetic edit is confirmed for free, while a substantive one becomes an update-mode item that the driver turns into a minimal edit.
- **The cycle:** `prosetta:cycle`, scheduled, syncs, confirms, builds the work, runs it through the existing resilient `Translator`, and finishes with `FinishCycle`, which approves per config, exports, records the heartbeat and reports.
- **Durable state:** usage and state live in two small tables.

**Tech Stack:** PHP 8.3+, Laravel 11–13, Pest 5, Orchestra Testbench 11; Undaunted: Laravel 13, laravel/ai, Inertia + React 19, Vitest (vp).

**Spec:** `docs/superpowers/specs/2026-09-23-background-mode-design.md` (read it first; it is the binding authority).

## Global Constraints

- **Style and structure:**
  - `declare(strict_types=1);` in every new PHP file.
  - Prosetta keeps its existing style (four-space indents, braces on the same line, `final readonly class` for stateless services). Undaunted follows its own style and Pint.
  - New Prosetta events are `final readonly` without `use Dispatchable` (ruling R2 from the resilience build).
  - Cache access in Prosetta goes through `Settings::cacheStore()` (the `CacheStore` wrapper), never `Settings::cache()` directly in new code.
- **Dependencies and schema:**
  - No new Composer or npm dependencies.
  - All new schema is additive: new nullable or defaulted columns, and new tables. Existing create migrations are not edited.
  - Table names resolve through `Settings::table()`: new names `usage` → `prosetta_usage` and `state` → `prosetta_state`.
- **Behavior:**
  - `automation.every` defaults to `null`, so nothing runs until a host turns it on.
  - Halted, suspended and budget-stopped jobs still end with `delete()`. The resilience layer's behavior is unchanged except where this plan names it.
  - Undaunted's values: `automation.every` 30, `approve` `'all'`, `export` true, `rewrite_ratio` 3.0. Spanish, Arabic and Chinese have `auto_translate` on; `en_GB` has it off.
- **Commits:**
  - Messages are multi-line, with a subject, a blank line, an optional body, a blank line, then these trailers on their own lines:
    ```
    Co-Authored-By: <the model that wrote the commit> <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2
    ```
  - Never delete anything through Undaunted's `vendor/lonely-lights/prosetta` (it's a junction to the package).
- **Tests to run:**
  - Prosetta: `vendor/bin/pest` in `C:\Websites\packages\prosetta` (270 passing at the start).
  - Undaunted: `php artisan test --parallel` (736 passed + 2 skipped at the start), `npm run -s test:run`, and `vendor/bin/pint --test app config tests database`.

## Review Focus

1. **A key edited twice before any update, with languages stale from different edits.** Each language must be diffed against the English it was approved from, never the key's intermediate value. (Task 3 test: "each language keeps the English it was approved against".)
2. **A cycle starting while the previous cycle's batch is still running, or after a cache clear.** It must skip, never double-queue. (Task 7 test: "skips while the previous cycle's batch is unfinished, even after a cache clear".)
3. **Auto-approval must never approve a draft with any issue,** including warnings (glossary, rewrite). (Task 7 test: "approves only drafts with no issues at all".)
4. **The glossary matches whole words case-insensitively,** so "cohorts" matches "cohort", but "cohortless" doesn't match, and a Latin term inside Arabic text is found. (Task 2 tests.)
5. **Budgets after a cache clear:** daily spending must still count. (Task 6 test: "keeps counting spending after the cache is cleared".)

---

## File Structure

**Prosetta, new:**
- `database/migrations/2026_09_23_000100_add_automation_columns.php`: adds the locale and translation columns, and creates the usage and state tables. Guarded with `hasColumn` and `hasTable`, so it runs cleanly on any host.
- `src/Guard/GlossaryGuard.php`
- `src/Translation/SourceChange.php`
- `src/Support/State.php`: key/value access to `prosetta_state`.
- `src/Resilience/UsageLedger.php`: writes and sums `prosetta_usage`.
- `src/Automation/CycleWork.php`: builds the cycle's work list.
- `src/Automation/CosmeticConfirmer.php`
- `src/Automation/Cycle.php`: orchestrates a cycle.
- `src/Automation/CycleReport.php`
- `src/Jobs/FinishCycle.php`
- `src/Events/CycleCompleted.php`
- `src/Console/CycleCommand.php`, `src/Console/HealthCommand.php`
- `src/Enums/SuspensionReason.php`

**Prosetta, modified:**
- Config and data classes: `config/prosetta.php`, `src/Models/Locale.php`, `src/Models/Translation.php`, `src/Data/LocaleDescriptor.php`, `src/Data/TranslationItem.php`, `src/Locales/DatabaseLocaleSource.php`, `src/Locales/ConfigLocaleSource.php`.
- Review, sync and translation: `src/Enums/ReviewAction.php`, `src/Review/ReviewService.php`, `src/Sync/Syncer.php`, `src/Translation/TranslationRunner.php`, `src/Translation/TranslateReport.php`, `src/Translation/Translator.php`, `src/Translation/Estimator.php`.
- Resilience: `src/Resilience/Budget.php`, `src/Resilience/ProviderGate.php`.
- Jobs and commands: `src/Jobs/TranslateBatch.php`, `src/Console/CircuitCommand.php`, `src/ProsettaServiceProvider.php`, `src/ProsettaManager.php`.
- Docs: `README.md`, `docs/handoff/2026-09-22-undaunted-adoption.md`.

**Undaunted, modified or new:**
- Migrations: `database/migrations/0050_locales/0050_01_01_000600_add_prosetta_automation_columns.php` (a copy of the package migration), `database/migrations/0050_locales/0050_01_01_000700_move_translation_style_notes.php` (a data migration).
- Driver and config: `app/Services/Translation/LaravelAiTranslationDriver.php`, `app/Ai/Agents/Translator.php`, `config/prosetta.php`; `config/translation.php` is deleted.
- Seeding: `database/seeders/LocalesSeeder.php`.
- Bridge: `app/Modules/Bridge/Http/Requests/Locales/UpdateLocaleRequest.php`, `app/Modules/Bridge/Http/Controllers/Locales/UpdateController.php`, `app/Modules/Bridge/Http/Controllers/Locales/IndexController.php`, `resources/js/modules/bridge/components/LocaleDialog/LocaleDialog.tsx`, `resources/js/modules/bridge/lib/types.ts`, `app/Modules/Bridge/Lang/en/locales.php`.
- Notifications: `app/Notifications/Translation/*` and `app/Listeners/Translation/NotifyTranslationAlerts.php`.
- Scheduling and env: `routes/console.php`, `.env.example`.
- Tests: `tests/Pest.php`, and new tests under `tests/Feature/Ai`, `tests/Feature/Modules/Bridge` and `tests/Feature/Translation`.

---

### Task 1: Schema, language settings and the locale descriptor

**Files:**
- Create: `database/migrations/2026_09_23_000100_add_automation_columns.php`
- Modify: `config/prosetta.php` (the `table_names` gains `usage` and `state`; the new `automation` block), `src/Models/Locale.php`, `src/Models/Translation.php`, `src/Data/LocaleDescriptor.php`, `src/Locales/DatabaseLocaleSource.php`, `src/Locales/ConfigLocaleSource.php`
- Test: `tests/Feature/Locales/LocaleSettingsTest.php`

**Interfaces:**
- Produces:
  - `LocaleDescriptor::__construct(string $code, string $englishName, string $nativeName, ?string $script = null, bool $rtl = false, ?string $styleNote = null, array $glossary = [])`, where `$glossary` is a `list<array{source: string, target: string, banned: list<string>}>`. `toArray()` includes `styleNote` and `glossary`.
  - `Locale`: fillable and cast `auto_translate` (boolean), `style_note` (string), `glossary` (array); a `scopeAutoTranslate(Builder $query)`; `toDescriptor()` passes the note and glossary.
  - `DatabaseLocaleSource::find()/targets()`: when a regional locale (e.g. `es_MX`, `zh-CN`) has a null `style_note` or empty `glossary`, it inherits them from its base language's row (`LocaleCode::language($code)`), if that row exists.
  - `DatabaseLocaleSource::autoTranslateTargets(): list<string>`: codes of target locales with `auto_translate` true.
  - Columns: `prosetta_locales.auto_translate` (bool, default false), `style_note` (text null), `glossary` (json null); `prosetta_translations.approved_source_value` (text null); table `prosetta_usage` (`id`, `run_id` string null, `circuit` string, `locale` string(35), `input_tokens` unsigned int, `output_tokens` unsigned int, `created_at` timestamp, with indexes on `created_at` and `run_id`); table `prosetta_state` (`key` string primary, `value` json, `updated_at` timestamp).
  - Config `automation` block exactly as spec §10.

- [ ] **Step 1: Write the failing test** (`tests/Feature/Locales/LocaleSettingsTest.php`)

```php
<?php

use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;
use LonelyLights\Prosetta\Models\Locale;

beforeEach(fn () => $this->seedLocales());

it('adds the automation columns and tables', function () {
    expect(Schema::hasColumns('prosetta_locales', ['auto_translate', 'style_note', 'glossary']))->toBeTrue()
        ->and(Schema::hasColumn('prosetta_translations', 'approved_source_value'))->toBeTrue()
        ->and(Schema::hasTable('prosetta_usage'))->toBeTrue()
        ->and(Schema::hasTable('prosetta_state'))->toBeTrue();
});

it('carries the style note and glossary to the descriptor', function () {
    Locale::findByCode('es')->update([
        'style_note' => 'Use tú.',
        'glossary' => [['source' => 'cohort', 'target' => 'cohorte', 'banned' => ['grupo']]],
    ]);

    $descriptor = app(DatabaseLocaleSource::class)->find('es');

    expect($descriptor->styleNote)->toBe('Use tú.')
        ->and($descriptor->glossary)->toBe([['source' => 'cohort', 'target' => 'cohorte', 'banned' => ['grupo']]]);
});

it('lets a regional locale inherit its language\'s note and glossary', function () {
    Locale::findByCode('en')->update(['style_note' => 'US spelling.', 'glossary' => [['source' => 'color', 'target' => 'colour', 'banned' => []]]]);

    $descriptor = app(DatabaseLocaleSource::class)->find('en_GB');

    expect($descriptor->styleNote)->toBe('US spelling.')
        ->and($descriptor->glossary)->toHaveCount(1);
});

it('lists the auto-translate targets', function () {
    Locale::findByCode('es')->update(['auto_translate' => true]);

    expect(app(DatabaseLocaleSource::class)->autoTranslateTargets())->toBe(['es']);
});

it('ships automation off by default', function () {
    expect(config('prosetta.automation'))->toBe(['every' => null, 'approve' => 'all', 'export' => true, 'rewrite_ratio' => 3.0]);
});
```

- [ ] **Step 2: Run it** — `vendor/bin/pest tests/Feature/Locales/LocaleSettingsTest.php`. Expected: FAIL (missing columns, unknown named argument, null config).

- [ ] **Step 3: Implement**

The migration (`database/migrations/2026_09_23_000100_add_automation_columns.php`):
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Background mode: per-language automation settings, the English each
 * approved translation was made from, and two durable tables (token usage,
 * and small state such as the cycle heartbeat). Guarded, so it runs cleanly
 * on hosts that own the locales table or migrate in a different order.
 */
return new class extends Migration {
    public function up(): void {
        $locales = Settings::table('locales');

        if (Schema::hasTable($locales) && ! Schema::hasColumn($locales, 'auto_translate')) {
            Schema::table($locales, function (Blueprint $table): void {
                $table->boolean('auto_translate')->default(false);
                $table->text('style_note')->nullable();
                $table->json('glossary')->nullable();
            });
        }

        $translations = Settings::table('translations');

        if (Schema::hasTable($translations) && ! Schema::hasColumn($translations, 'approved_source_value')) {
            Schema::table($translations, fn (Blueprint $table) => $table->text('approved_source_value')->nullable());
        }

        if (! Schema::hasTable(Settings::table('usage'))) {
            Schema::create(Settings::table('usage'), function (Blueprint $table): void {
                $table->id();
                $table->string('run_id')->nullable()->index();
                $table->string('circuit');
                $table->string('locale', 35);
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->timestamp('created_at')->index();
            });
        }

        if (! Schema::hasTable(Settings::table('state'))) {
            Schema::create(Settings::table('state'), function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->json('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('state'));
        Schema::dropIfExists(Settings::table('usage'));

        if (Schema::hasColumn(Settings::table('translations'), 'approved_source_value')) {
            Schema::table(Settings::table('translations'), fn (Blueprint $table) => $table->dropColumn('approved_source_value'));
        }

        if (Schema::hasColumn(Settings::table('locales'), 'auto_translate')) {
            Schema::table(Settings::table('locales'), fn (Blueprint $table) => $table->dropColumn(['auto_translate', 'style_note', 'glossary']));
        }
    }
};
```
The locales and workflow migrations run in both orders in hosts, but the testbench loads both folders before this file (the filename date sorts after them). In `ProsettaServiceProvider`, add this file to the `prosetta-migrations` publish list, beside the four existing entries.

In `config/prosetta.php`: add `'usage' => 'prosetta_usage', 'state' => 'prosetta_state',` to `table_names`, and before `'log_channel'` add:
```php
    /*
    | Background mode. every: minutes between scheduled prosetta:cycle runs
    | (null = no scheduled cycle). approve: 'all', 'none' (stop at drafts), or
    | a list of language codes whose clean drafts are approved automatically.
    | export: write lang files after approving. rewrite_ratio: flag an update
    | whose translation changed this many times more than the English did.
    */
    'automation' => [
        'every' => null,
        'approve' => 'all',
        'export' => true,
        'rewrite_ratio' => 3.0,
    ],
```
Update `LocaleDescriptor` (add the two promoted params and extend `toArray()`), the `Locale` fillable, casts, scope and `toDescriptor()`, and the `Translation` fillable (`approved_source_value`).

In `DatabaseLocaleSource`, add the inheritance in a private `describe(Locale $locale): LocaleDescriptor`, used by `find()` and `targets()`:
```php
    private function describe(Locale $locale): LocaleDescriptor {
        $descriptor = $locale->toDescriptor();
        $language = LocaleCode::language($descriptor->code);

        if ($language === $descriptor->code || ($descriptor->styleNote !== null && $descriptor->glossary !== [])) {
            return $descriptor;
        }

        $model = Settings::model('locale');
        $base = $model::query()->where('locale_initials', $language)->first();

        return $base === null ? $descriptor : new LocaleDescriptor(
            $descriptor->code, $descriptor->englishName, $descriptor->nativeName, $descriptor->script, $descriptor->rtl,
            $descriptor->styleNote ?? $base->style_note,
            $descriptor->glossary !== [] ? $descriptor->glossary : (array) ($base->glossary ?? []),
        );
    }

    /** @return list<string> */
    public function autoTranslateTargets(): array {
        $model = Settings::model('locale');

        return $model::query()->scopes(['targets', 'autoTranslate'])
            ->where('locale_initials', '!=', $this->source())
            ->pluck('locale_initials')->values()->all();
    }
```
Check `LocaleCode::language()` first (src/Support/LocaleCode.php): `language('zh-CN')` must be `'zh'` and `language('en_GB')` `'en'`. `ConfigLocaleSource` keeps passing no note or glossary (the defaults), and gets an `autoTranslateTargets()` returning `[]`. Add `autoTranslateTargets(): array` to the `LocaleSource` contract.

- [ ] **Step 4: Run it, then the full suite** (`vendor/bin/pest`; all green).
- [ ] **Step 5: Commit** — "Add per-language automation settings, the approved-source column and the usage and state tables".

---

### Task 2: Glossary guard

**Files:**
- Create: `src/Guard/GlossaryGuard.php`
- Modify: `src/Translation/TranslationRunner.php` (run the glossary guard after the placeholder guard)
- Test: `tests/Unit/Guard/GlossaryGuardTest.php`, `tests/Feature/Translation/TranslationRunnerTest.php` (append)

**Interfaces:**
- Consumes: `LocaleDescriptor::$glossary` (Task 1).
- Produces: `GlossaryGuard::check(string $source, string $translation, array $glossary): list<Issue>`. The codes are `glossary_missing` (a warning) and `glossary_banned` (an error).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Guard/GlossaryGuardTest.php`:
```php
<?php

use LonelyLights\Prosetta\Guard\GlossaryGuard;

$glossary = [['source' => 'cohort', 'target' => 'دفعة', 'banned' => ['فوج', 'مجموعة']]];

it('passes a translation that uses the required term', function () use ($glossary) {
    expect((new GlossaryGuard)->check('Your cohort convenes.', 'تلتقي دفعتك. دفعة', $glossary))->toBe([]);
});

it('warns when the required term is missing, and matches whole words case-insensitively', function () use ($glossary) {
    $issues = (new GlossaryGuard)->check('Two Cohorts arrive.', 'يصل فوجان.', $glossary);

    expect(array_map(fn ($issue) => $issue->code, $issues))->toBe(['glossary_missing'])
        ->and((new GlossaryGuard)->check('A cohortless start.', 'بداية', $glossary))->toBe([]);
});

it('errors on a banned term, which blocks', function () use ($glossary) {
    $issues = (new GlossaryGuard)->check('Your cohort convenes.', 'يلتقي فوجك مع دفعة.', $glossary);

    expect(array_map(fn ($issue) => $issue->code, $issues))->toBe(['glossary_banned'])
        ->and($issues[0]->isBlocking())->toBeTrue()
        ->and($issues[0]->message)->toContain('دفعة')->toContain('فوج');
});

it('checks nothing when the glossary is empty', function () {
    expect((new GlossaryGuard)->check('Your cohort convenes.', 'x', []))->toBe([]);
});
```
The "Two Cohorts" case: "cohort" must match the plural "Cohorts" as a word stem. Use the pattern `/\b{source}(s|es)?\b/iu` on the English. The translation-side check is a plain case-insensitive substring match, because Arabic and Chinese have no ASCII word boundaries.

Append to `TranslationRunnerTest.php`:
```php
it('runs the glossary guard with the target\'s glossary', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    \LonelyLights\Prosetta\Models\Locale::findByCode('es')->update(['glossary' => [['source' => 'credentials', 'target' => 'credenciales', 'banned' => []]]]);
    app()->instance(TranslationDriver::class, new \LonelyLights\Prosetta\Testing\ScriptedDriver);

    app(TranslationRunner::class)->run('es', keyIds('auth.failed'), force: true);

    $issues = Translation::query()->where('key_id', keyIds('auth.failed')[0])->where('locale', 'es')->value('issues');
    expect(collect($issues)->pluck('code')->all())->toContain('glossary_missing');
});
```
(The scripted driver echoes the English plus " [es]", so "credenciales" is missing.)

- [ ] **Step 2: Run them.** Expected: FAIL (class not found; no glossary issue).
- [ ] **Step 3: Implement**
```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Guard;

/**
 * A language's required and banned terms. When an entry's English term
 * appears in the source (as a whole word, singular or plural, any case), the
 * translation must contain its target (a warning if not) and none of its
 * banned alternatives (an error, which gets the normal retry). The target
 * side is a plain case-insensitive substring match, since many scripts have
 * no word boundaries.
 */
final readonly class GlossaryGuard {
    /**
     * @param list<array{source: string, target: string, banned?: list<string>}> $glossary
     * @return list<Issue>
     */
    public function check(string $source, string $translation, array $glossary): array {
        $issues = [];

        foreach ($glossary as $entry) {
            $term = trim((string) ($entry['source'] ?? ''));

            if ($term === '' || preg_match('/\b'.preg_quote($term, '/').'(s|es)?\b/iu', $source) !== 1) {
                continue;
            }

            $target = (string) ($entry['target'] ?? '');

            foreach ((array) ($entry['banned'] ?? []) as $banned) {
                if ($banned !== '' && mb_stripos($translation, (string) $banned) !== false) {
                    $issues[] = Issue::error('glossary_banned', "Use \"$target\" for \"$term\", not \"$banned\".");
                }
            }

            if ($target !== '' && mb_stripos($translation, $target) === false) {
                $issues[] = Issue::warning('glossary_missing', "\"$term\" should be translated as \"$target\".");
            }
        }

        return $issues;
    }
}
```
Wait: in the banned test, the translation contains both فوج and دفعة, so only `glossary_banned` is raised. In `TranslationRunner::attempt()`, where `issues` is built for a string value, append the glossary issues: `[...$this->guard->check($item->source, $value, $locale), ...$this->glossary->check($item->source, $value, $batch->target->glossary)]`. Inject `GlossaryGuard $glossary` in the constructor.

- [ ] **Step 4: Run them, then the full suite.**
- [ ] **Step 5: Commit** — "Check each language's glossary: warn on a missing term, retry on a banned one".

---

### Task 3: The English each approval was made from, and the confirm action

**Files:**
- Modify: `src/Enums/ReviewAction.php` (add `case Confirmed = 'confirmed';`), `src/Review/ReviewService.php`, `src/Sync/Syncer.php`, `src/ProsettaManager.php` (add a `confirm()` pass-through)
- Test: `tests/Feature/Review/ApprovedSourceTest.php`

**Interfaces:**
- Consumes: the `approved_source_value` column (Task 1).
- Produces:
  - **Every approval path sets `approved_source_value`:** `markApproved()` (used by `approve`, `edit(approve: true)`, `write(approve: true)` and `approveClean`), and the Syncer's import of clean target values. It's set to the key's current `source_value` when the approved `source_hash` equals the key's `source_hash`, otherwise null.
  - **`ReviewService::confirm(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation`:** requires an approved translation that is stale. It sets `value` and `approved_value` to the current approved value, `source_hash`/`approved_source_hash` to the key's current hash, and `approved_source_value` to the key's current source. It keeps `origin` and `ai_*`, sets status approved, and logs `ReviewAction::Confirmed`. It needs Review permission, raises `TranslationApproved`, and throws a `ProsettaException` when the translation isn't approved or isn't stale.
  - `ProsettaManager::confirm(int $translationId, ?Authenticatable $by, ?string $notes = null): Translation`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Review/ApprovedSourceTest.php`)
```php
<?php

use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function spanishFailed(): Translation {
    return Translation::query()->where('key_id', app(KeyFinder::class)->find('auth.failed')->id)->where('locale', 'es')->firstOrFail();
}

function editEnglishFailed(string $fixture, string $to): void {
    $path = $fixture.'/lang/en/auth.php';
    file_put_contents($path, str_replace("'These credentials do not match our records.'", var_export($to, true), file_get_contents($path)));
    app(Syncer::class)->sync();
}

it('records the English an imported translation was approved against', function () {
    expect(spanishFailed()->approved_source_value)->toBe('These credentials do not match our records.');
});

it('records it on approval through write, edit and approve', function () {
    $translation = app(ReviewService::class)->write('auth.throttle', 'es', 'Demasiados intentos. Inténtalo de nuevo en :seconds segundos.', null, approve: true);

    expect($translation->approved_source_value)->toBe('Too many login attempts. Please try again in :seconds seconds.');
});

it('each language keeps the English it was approved against', function () {
    $original = 'These credentials do not match our records.';
    app(ReviewService::class)->write('auth.failed', 'ar', 'بيانات الاعتماد هذه لا تطابق سجلاتنا.', null, approve: true);
    editEnglishFailed($this->fixture, 'These details do not match our records.');
    app(ReviewService::class)->write('auth.failed', 'es', 'Estos datos no coinciden con nuestros registros.', null, approve: true);
    editEnglishFailed($this->fixture, 'These details do not match anything we have.');

    $arabic = Translation::query()->where('key_id', app(KeyFinder::class)->find('auth.failed')->id)->where('locale', 'ar')->first();

    expect($arabic->approved_source_value)->toBe($original)
        ->and(spanishFailed()->approved_source_value)->toBe('These details do not match our records.');
});

it('confirms a stale translation without changing it, keeping its origin', function () {
    editEnglishFailed($this->fixture, 'These credentials do not match our records!');
    $before = spanishFailed();

    $confirmed = app(ReviewService::class)->confirm($before->id, null, 'cosmetic');

    expect($confirmed->approved_value)->toBe($before->approved_value)
        ->and($confirmed->origin)->toBe(TranslationOrigin::Imported)
        ->and($confirmed->approved_source_value)->toBe('These credentials do not match our records!')
        ->and(\LonelyLights\Prosetta\Support\WorkState::isStale($confirmed->key, $confirmed))->toBeFalse()
        ->and($confirmed->reviews()->latest('id')->value('action'))->toBe(ReviewAction::Confirmed);
});

it('refuses to confirm a translation that is current', function () {
    expect(fn () => app(ReviewService::class)->confirm(spanishFailed()->id, null))->toThrow(\LonelyLights\Prosetta\Exceptions\ProsettaException::class);
});
```
Read `WorkState::isStale()` first and use its real signature. If the reviews relation or its `action` cast differs, adjust the last assertion to the codebase's actual review-log shape (see `Translation::logReview()`).

- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement**
  - In `markApproved()`, add to the update array: `'approved_source_value' => $translation->source_hash === $translation->key->source_hash ? $translation->key->source_value : null,`.
  - In `Syncer::importTarget()`, set `'approved_source_value' => $clean ? $model->source_value : null` beside `approved_value`.
  - Add `confirm()` to ReviewService:
    - authorize Review for the translation's locale;
    - load the translation and its key;
    - if `approved_value === null` or `approved_source_hash === key->source_hash`, throw `new ProsettaException('Only a stale approved translation can be confirmed.')`;
    - in `DB::transaction`, update `value`, `approved_value` (both to `approved_value`), `source_hash`, `approved_source_hash` and `approved_source_value` from the key, with status Approved, `reviewed_by`, `reviewed_at` and `issues` null;
    - `logReview(ReviewAction::Confirmed, …, newValue: approved_value, notes)`;
    - dispatch `TranslationApproved`;
    - return `refresh()`.
  - Add the manager pass-through.
- [ ] **Step 4: Run them, then the full suite.**
- [ ] **Step 5: Commit** — "Remember the English each approval was made from, and confirm stale translations without changing them".

---

### Task 4: SourceChange (classify, diff, ratio)

**Files:**
- Create: `src/Translation/SourceChange.php`
- Test: `tests/Unit/Translation/SourceChangeTest.php`

**Interfaces:**
- Produces:
  - `SourceChange::isCosmetic(string $old, string $new): bool`
  - `SourceChange::diff(string $old, string $new): string`: a readable word-level diff, e.g. `"[-credentials-] {+details+} do not match"`, computed with a word LCS.
  - `SourceChange::ratio(string $oldSource, string $newSource, string $oldTranslation, string $newTranslation): float`: normalized translation word edit distance ÷ max(normalized source word edit distance, 0.01).
  - `SourceChange::changedWords(string $old, string $new): int`: the word-level edit distance.

- [ ] **Step 1: Write the failing test**
```php
<?php

use LonelyLights\Prosetta\Translation\SourceChange;

it('treats whitespace, quote style, dashes, trailing punctuation and case as cosmetic', function (string $old, string $new) {
    expect(SourceChange::isCosmetic($old, $new))->toBeTrue();
})->with([
    ['Save  changes', 'Save changes'],
    ["Don't go", 'Don’t go'],
    ['A - B', 'A — B'],
    ['Saved', 'Saved.'],
    ['Log In', 'Log in'],
]);

it('treats any word change, including spelling, as substantive', function (string $old, string $new) {
    expect(SourceChange::isCosmetic($old, $new))->toBeFalse();
})->with([
    ['Colour', 'Color'],
    ['Save changes', 'Save all changes'],
    ['Deleted', 'Removed'],
]);

it('writes a word-level diff', function () {
    expect(SourceChange::diff('These credentials do not match our records.', 'These details do not match our records.'))
        ->toBe('These [-credentials-] {+details+} do not match our records.');
});

it('measures how much more the translation changed than the English', function () {
    $small = SourceChange::ratio('Save changes', 'Save all changes', 'Guardar cambios', 'Guardar todos los cambios');
    $rewrite = SourceChange::ratio('Save changes', 'Save all changes', 'Guardar cambios', 'Almacena todas tus modificaciones ahora');

    expect($small)->toBeLessThan(3.0)
        ->and($rewrite)->toBeGreaterThan(3.0)
        ->and(SourceChange::changedWords('a b c', 'a x c'))->toBe(1);
});
```
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement.** Words are `preg_split('/\s+/u', trim($text))`. The edit distance is word-level Levenshtein (a DP over word arrays). For `ratio`, divide each distance by the longer word count on its side before comparing. `isCosmetic` normalizes both strings and compares for equality:
  - lowercase with `mb_strtolower`;
  - curly quotes → straight (`’‘` → `'`, `“”` → `"`);
  - `—–` → `-`;
  - collapse whitespace;
  - trim trailing `.!?…:;` and whitespace.

  `diff` walks the LCS of the word arrays and emits kept words plain, removed ones as `[-w-]` and added ones as `{+w+}`. Adjacent removed or added words are grouped into one bracket, as in the test.
- [ ] **Step 4: Run it, then the full suite.**
- [ ] **Step 5: Commit** — "Classify, diff and measure English edits without AI".

---

### Task 5: Update mode in the runner

**Files:**
- Modify: `src/Data/TranslationItem.php` (add `?string $previousSource = null` as the last constructor parameter), `src/Translation/TranslationRunner.php`, `src/Translation/TranslateReport.php` (add `public array $updated = []`, merged and in `toArray()`)
- Test: `tests/Feature/Translation/UpdateModeTest.php`

**Interfaces:**
- Consumes: `approved_source_value` (Tasks 1, 3); `SourceChange::ratio()` and `changedWords()` (Task 4); `automation.rewrite_ratio`.
- Produces:
  - **`TranslationItem::$previousSource`:** set by the runner when the existing translation has an `approved_value` and an `approved_source_value`, and that source differs from the key's current source.
  - **After such an item's result passes the guards,** the runner computes `SourceChange::ratio(previousSource, source, previous, value)`. When the ratio is above `automation.rewrite_ratio` and `changedWords(previous, value) > 2`, it adds `Issue::warning('large_rewrite', 'The update changed much more of the translation than the English changed.')`.
  - **The report:** refs of drafts made in update mode are added to `TranslateReport::$updated`, as well as to `$drafted`.

- [ ] **Step 1: Write the failing tests** (`UpdateModeTest.php`, using `ScriptedDriver` subclasses that return set values)
  - **"sends the English a translation was approved against as previousSource":** edit the English `auth.failed` in the fixture (the `editEnglishFailed` helper from Task 3: copy it into this file under another name, since helpers don't cross test files). Run the runner for es. Assert the driver's single call has an item whose `previousSource` is the original English and whose `previous` is the approved Spanish.
  - **"leaves previousSource null for a key with no approved translation":** `auth.throttle` in es.
  - **"flags an update that rewrote far more than the English changed":** edit `auth.failed` by one word. The driver returns a completely different Spanish sentence of 8 words. Assert the issue codes contain `large_rewrite` and the report's `updated` has `'es auth.failed'`.
  - **"accepts a minimal update":** same edit; the driver returns the old Spanish with one word changed. Assert no `large_rewrite`.

  Write the tests with real assertions, e.g.:
```php
it('flags an update that rewrote far more than the English changed', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    editEnglish($this->fixture, 'These details do not match our records.');
    $driver = new class extends \LonelyLights\Prosetta\Testing\ScriptedDriver {
        public function translate(\LonelyLights\Prosetta\Data\TranslationBatch $batch): \LonelyLights\Prosetta\Data\TranslationBatchResult {
            $this->calls[] = $batch;

            return new \LonelyLights\Prosetta\Data\TranslationBatchResult(
                array_fill_keys(array_map(fn ($item) => $item->id, $batch->items), 'Algo completamente distinto que nadie pidió cambiar hoy'),
                'fake', 'm', 10, 10,
            );
        }
    };
    app()->instance(TranslationDriver::class, $driver);

    $report = app(TranslationRunner::class)->run('es', keyIds('auth.failed'));

    $issues = Translation::query()->where('key_id', keyIds('auth.failed')[0])->where('locale', 'es')->value('issues');
    expect(collect($issues)->pluck('code')->all())->toContain('large_rewrite')
        ->and($report->updated)->toBe(['es auth.failed']);
});
```
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** in `run()`, where items are built:
```php
        $items = $keys->map(function (TranslationKey $key) use ($existing) {
            $current = $existing->get($key->getKey());
            $previousSource = $current?->approved_value !== null && $current->approved_source_value !== null && $current->approved_source_value !== $key->source_value
                ? $current->approved_source_value
                : null;

            return new TranslationItem((string) $key->getKey(), $key->ref()->toString(), $key->source_value, $key->context, $key->max_length, $key->placeholders ?? [], $current?->approved_value, $previousSource);
        })->values()->all();
```
In `attempt()`, after the guard and glossary issues for a string value: if the item has a `previousSource` and a `previous`, apply the rewrite check. In `persist()`, when `$item->previousSource !== null`, also push to `$report->updated`. Carry the item into `persist()`, or keep an `updateIds` set built in `run()`.
- [ ] **Step 4: Run them, then the full suite.**
- [ ] **Step 5: Commit** — "Send edits as minimal updates, and flag an update that rewrote more than the English changed".

---

### Task 6: Durable usage and budgets, and the estimator's per-string rates

**Files:**
- Create: `src/Resilience/UsageLedger.php`
- Modify: `src/Resilience/Budget.php`, `src/Resilience/ProviderGate.php`, `src/Translation/Estimator.php`, `src/Translation/TranslationRunner.php` (pass the locale to the gate)
- Test: `tests/Feature/Resilience/BudgetTest.php` (rework), `tests/Feature/Translation/EstimatorTest.php` (append)

**Interfaces:**
- Produces:
  - `UsageLedger::record(?string $runId, string $circuit, string $locale, int $input, int $output): void`
  - `UsageLedger::sum(?string $runId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): int`
  - **`ProviderGate::call(TranslationDriver $driver, string $circuit, ?string $runId, Closure $call, ?string $locale = null)`** (a new optional last parameter): it writes a usage row per successful call (with locale `''` when null), instead of cache counters. The runner passes `$locale`.
  - **`Budget::exhausted/record/usage`:** same signatures, but they sum from `UsageLedger`. Daily is today's rows in the app time zone, monthly this month's, per_run the run's. `record()` no longer increments anything (the gate's ledger row is the record). It only checks whether a period has just crossed its limit, and dispatches `BudgetReached` once per period through `Settings::cacheStore()->add("prosetta:budget:$period:$periodKey:reached", true, $ttl)`.
  - **Estimator history:** a least-squares fit of `tokens = a·chars + b·strings` per side over the locale's recent `prosetta_usage`… In practice usage rows aren't per string, so fit per-string and per-character rates from AI translations instead: `input_tokens` and `output_tokens` against source length. The rates are the median per-string tokens split into per-string and per-character parts, using the default split ratio from config, scaled to the locale's median. Keep it simple and deterministic: `perItem = config per_item × (history median tokens per string ÷ default tokens per string of the same length)`, and the same scale for per-char. Test that a locale with short-string history no longer overestimates long strings by more than 1.5×, compared with history computed by the formula.

- [ ] **Step 1: Write the failing tests**
  - `BudgetTest.php`: keep its five behaviors, but drive them through `UsageLedger::record()` instead of `Budget::record()`'s old counters, and add:
```php
it('keeps counting spending after the cache is cleared', function () {
    config(['prosetta.budgets.daily' => 1000]);
    app(\LonelyLights\Prosetta\Resilience\UsageLedger::class)->record('run-1', 'c', 'es', 600, 500);

    \Illuminate\Support\Facades\Cache::store('array')->flush();

    expect(app(Budget::class)->exhausted('run-2'))->toBe('daily');
});
```
  - A gate test in `ProviderGateTest.php`: after a successful `callThrough`, one `prosetta_usage` row exists with the call's tokens.
  - `EstimatorTest.php`: "does not overestimate long strings from short-string history". Seed 50 AI drafts of 10-character sources with 40 input and 20 output tokens each (short strings, overhead-heavy). Then estimate a work list of long strings (e.g. five keys of 300 characters, created as in the existing history test) and assert the input estimate is ≤ 1.5 × (5 × (40 + 0.3 × 290)).
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.** `UsageLedger` uses `DB::table(Settings::table('usage'))`. `Budget` keeps `periods()` for naming and dates, but computes `used` with `UsageLedger::sum()`. `ProviderGate::call()` gains a `?string $locale = null` last parameter, and after a successful call writes the ledger row (inside the existing `try`, before `recordSuccess`, replacing `budget->record`'s counting), then calls `budget->record($runId)` for the crossing check. `Budget::record(?string $runId, int $tokens)` keeps its signature for compatibility; `$tokens` is ignored and the event is based on the ledger sums. The runner passes `$locale` into `gate->call(...)`.

  For the estimator:
  - `history()` returns per-string medians: `inPerItem = median(input_tokens)`, `outPerItem = median(output_tokens)`, and the median source length `L`.
  - `estimate()` becomes, for each work string of length c:
    `input = inPerItem × (defaultIn(c) ÷ defaultIn(L))`, where `defaultIn(x) = input_per_char × x + input_per_item`, and the same for output.
  - Sum over the strings. This scales history by the default model's shape, so short-string history doesn't inflate long strings.
- [ ] **Step 4: Run them, then the full suite.**
- [ ] **Step 5: Commit** — "Record token usage in the database, so budgets survive a cache clear; scale estimator history by string length".

---

### Task 7: The cycle

**Files:**
- Create: `src/Support/State.php`, `src/Automation/CycleWork.php`, `src/Automation/CosmeticConfirmer.php`, `src/Automation/Cycle.php`, `src/Automation/CycleReport.php`, `src/Jobs/FinishCycle.php`, `src/Events/CycleCompleted.php`, `src/Console/CycleCommand.php`
- Modify: `src/Translation/Translator.php` (a `run()` that takes a prepared work list, a scope and an optional batch-finally callback), `src/Review/ReviewService.php` (`approveClean(…, bool $strict = false)`: when strict, skip drafts with any issue), `src/ProsettaServiceProvider.php` (register the command; schedule it when `automation.every` is set)
- Test: `tests/Feature/Automation/CycleTest.php`

**Interfaces:**
- Consumes: Tasks 1–6; `Translator`, `Exporter`, `ReviewService`, `Suspensions`, `Syncer`.
- Produces:
  - `State::get(string $key, mixed $default = null): mixed`, `State::put(string $key, mixed $value): void` and `State::forget(string $key): void` (`prosetta_state`, JSON values).
  - `CycleWork::build(): array<string, array<int, list<int>>>` (locale → file id → key ids). It covers, for every target locale, keys whose translation is approved but stale and not cosmetic (update mode), plus, for auto-translate locales, keys with no translation or a missing or rejected candidate (the same "missing" rule as `ReviewQueue::missing()`).
  - `CosmeticConfirmer::confirmAll(): int`: confirms every stale approved translation whose `approved_source_value` → current source change is cosmetic, returning the count.
  - `Translator::run(array $work, RunScope $scope, bool $queue = true, ?Closure $finally = null, ?string $runId = null): Batch|TranslateReport`. `translate()` is refactored to call `workList()`, then `run()`. With a queue, `$finally` is attached with `->finally($finally)`. With no queue, `$runId` is used (default a new uuid).
  - `Cycle::run(bool $sync = false): CycleReport`
  - `FinishCycle::__construct(string $batchId, string $runId, int $startedAt, int $confirmed)`, whose `handle()` calls `Cycle::finish(...)`
  - `Cycle::finish(string $runId, int $startedAt, int $confirmed, ?TranslateReport $inline = null): CycleReport`
  - `CycleReport` (public, readonly-friendly):
    - `bool $skipped`, `string $reason`
    - `int $drafted`, `int $updated`, `int $confirmed`, `int $approved`
    - `list<string> $flagged`, `list<string> $files`
    - `int $tokens`, `?string $batchId`
    - `toArray()`
  - Event `CycleCompleted(CycleReport $report)`.
  - `prosetta:cycle {--sync}`: `--sync` exits 1 when `flagged` isn't empty, anything is stale after the run, or suspensions exist; it exits 0 otherwise. Without `--sync` it prints the queued batch id, or "skipped: …".
  - The service provider schedules `prosetta:cycle` with `->cron('*/N * * * *')->withoutOverlapping()` when `automation.every` is set (N clamped 1–59, as for resume).

**Cycle::run** does, in order:
1. **Guard:** `$previous = State::get('cycle.batch')`. If it's set and `Bus::findBatch($previous['batch_id'])` exists and isn't finished, return a skipped report ("previous cycle still running").
2. **Sync:** `Syncer::sync()`.
3. **Confirm:** `$confirmed = CosmeticConfirmer::confirmAll()`.
4. **Build:** `$work = CycleWork::build()`, `$startedAt = now()->getTimestamp()`, `$runId = (string) Str::uuid()`.
5. **Empty work:** call `finish($runId, $startedAt, $confirmed)` and return.
6. **Sync mode:** `$report = Translator::run($work, $scope, queue: false, runId: $runId)`, then `return finish($runId, $startedAt, $confirmed, $report)`.
7. **Queued:** `$batch = Translator::run($work, $scope, queue: true, finally: fn (Batch $b) => FinishCycle::dispatch($b->id, $runIdForBatch…))`.
   - The batch id isn't known before dispatch, so use the batch id itself as the run id for queued cycles. The `finally` closure dispatches `FinishCycle($batch->id, $batch->id, $startedAt, $confirmed)`.
   - Queued jobs already use `$this->batchId` as their run id for usage rows, so usage by run id = batch id.
   - Then `State::put('cycle.batch', ['batch_id' => $batch->id, 'started_at' => $startedAt])` and return a report with `batchId`.

**Cycle::finish** does:
- **Approve:** depending on `automation.approve` — `'all'` → every target locale touched by this cycle; a list → the intersection; `'none'` → nothing — run `approveClean($locale, strict: true)` for each, summing approved counts.
- **Flagged:** refs of AI drafts updated since `$startedAt` that have any issue.
- **Export:** when `automation.export` and there are approvals, `Exporter::export(array_keys($approvedPerLocale))`; `files` = the written paths.
- **Tokens:** `UsageLedger::sum($runId)`.
- **Counts:** `drafted` = AI translations updated since `$startedAt` (inline: from the report); `updated` = those that had an `approved_value` before (inline: `count($report->updated)`).
- **State:** `State::put('cycle.last_run', now()->getTimestamp())` and `State::forget('cycle.batch')`.
- **Event:** dispatch `CycleCompleted`, and return the report.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Automation/CycleTest.php`; use the fixture app, `seedLocales()` and `ScriptedDriver`; set `prosetta.resilience.cache_store` to `array`). Each test must assert real outcomes:
  - **"drafts missing keys only for auto-translate languages":** es auto on, ar off. `Cycle::run(sync: true)` drafts es's missing keys; ar has no new AI drafts.
  - **"updates an edited key in every language that has it":** approve ar `auth.failed` by `write`, keep es imported; es and ar auto off; edit the English substantively. The cycle's driver calls include `auth.failed` for both es and ar, with `previousSource` set.
  - **"confirms a cosmetic edit without calling the driver":** edit `auth.failed`'s English by adding "!". The driver gets zero calls, `report->confirmed === 1`, and the es translation is current again.
  - **"approves only drafts with no issues at all":** the driver drops a placeholder for one key (the guard error survives the retry) and returns glossary-missing text for another. The report's `approved` excludes both and `flagged` contains both.
  - **"stops at drafts when approve is none":** `automation.approve = 'none'`, so `approved === 0` and drafts stay drafts.
  - **"approves only the listed languages":** `automation.approve = ['es']`, es and ar both auto. Only es drafts are approved.
  - **"exports only languages that got approvals":** approve `['es']`. `report->files` contains `lang/es/...` paths and none for ar.
  - **"skips while the previous cycle's batch is unfinished, even after a cache clear":** `Bus::fake()`, `State::put('cycle.batch', ['batch_id' => $id, …])` for a batch record that exists and is unfinished (insert a `job_batches` row via `Bus::batch([...])->dispatch()` under a fake with `pending_jobs` > 0, or use `DB::table('job_batches')->insert([...])` with the columns Laravel expects). Flush the array cache, then run: the report is skipped.
  - **"records the heartbeat and raises CycleCompleted, also when there is nothing to do":** an empty cycle sets `cycle.last_run` and dispatches the event with zeros.
  - **"queues one batch and finishes it through FinishCycle":** `Bus::fake()`, run queued; one batch is dispatched with a `finally` callback, and `cycle.batch` is stored. Call `(new FinishCycle(...))->handle(...)` directly: `cycle.batch` is cleared and the heartbeat recorded.
  - **"prosetta:cycle --sync exits 1 when anything is flagged, 0 when clean".**
  - **"schedules the cycle when automation.every is set":** as for resume.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** the classes per the interfaces above. `Translator::run()` is the old tail of `translate()` (from `$size` onwards), taking `$work` and `$scope` as parameters; `translate()` builds both and delegates. `approveClean(..., bool $strict = false)`: in its query, when strict, also require `issues` null or empty. Keep the existing behavior when not strict.
- [ ] **Step 4: Run them, then the full suite.**
- [ ] **Step 5: Commit** — "Add the scheduled cycle: sync, confirm cosmetic edits, update and draft, approve per config, export and report".

---

### Task 8: Health and status

**Files:**
- Create: `src/Console/HealthCommand.php`
- Modify: `src/Console/CircuitCommand.php` (print the last cycle time), `src/ProsettaServiceProvider.php` (register the command)
- Test: `tests/Feature/Console/HealthCommandTest.php`

**Interfaces:**
- Produces: `prosetta:health` prints one line per problem and exits 1 when any exists, or prints "healthy" and exits 0. The problems are:
  - automation is on and `cycle.last_run` is missing or older than 3 × `every` minutes;
  - any circuit is halted (`state` open and `reason` halt);
  - the daily or monthly budget is exhausted.

- [ ] **Step 1: Write the failing tests:** healthy when automation is off and nothing is wrong; unhealthy, with a line naming the problem, for each of the three reasons; and `prosetta:circuit` shows "Last cycle:".
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement** with `State`, `Circuits`, `Budget` and config.
- [ ] **Step 4: Run them, then the full suite.**
- [ ] **Step 5: Commit** — "Add prosetta:health and show the last cycle in prosetta:circuit".

---

### Task 9: Follow-ups from the resilience build

**Files:**
- Create: `src/Enums/SuspensionReason.php` (`Outage = 'outage'`, `Halted = 'halted'`, `Rejected = 'rejected'`, `Quota = 'quota'`, `Unknown = 'unknown'`, `Daily = 'daily'`, `Monthly = 'monthly'`)
- Modify: `src/Jobs/TranslateBatch.php` and `src/Translation/Translator.php` (both paths map to the enum's values; `Suspensions::suspend()` keeps a string parameter and receives `->value`), `src/Translation/Translator.php::refs()` (order by `(new $keyModel)->getKeyName()`), `tests/Feature/Console/ResilienceCommandsTest.php` (the merge test reads the stored `started_at`), `src/Translation/Translator.php` (in a sync run, a batch rejection during the retry pass keeps the runner's partial report: see below), `docs/superpowers/specs/2026-09-23-resilience-layer-design.md` (§5 step 2: "`failed_jobs` stays reserved for genuine bugs and batches the provider rejected"; §6: re-join the list the inserted paragraph broke)
- Test: `tests/Feature/Translation/TranslateBatchResilienceTest.php` and `tests/Feature/Translation/TranslatorTest.php` (append)

**Interfaces:**
- Produces:
  - **One reason vocabulary:** job and sync suspensions use the same words. An unknown-error halt suspends as `unknown` (the gate classifies unknown halts as `ProviderRejected` with a previous non-provider exception, so check `$e->getPrevious()` and whether it isn't a `ProviderException`).
  - **Partial reports:** `TranslationRunner::run()` attaches the partial report to the exception it rethrows, via a new `ProsettaException`-compatible holder: add `public ?TranslateReport $partial = null` to `ProviderException`, and set it before rethrowing. `Translator`'s sync loop merges `$e->partial` when present, before marking the chunk failed, and only marks keys that aren't in `partial->drafted` as failed.

- [ ] **Step 1: Write the failing tests:**
  - **Same reason word:** a sync-run halt from `ProviderRejected` and a queued-job halt from `ProviderRejected` both store the reason `rejected`; an unknown-error halt (`unknown_errors = 'halt'`) stores `unknown` on both paths.
  - **Partial report kept:** in a sync run where the retry pass gets `ProviderBatchRejected`, the report's `drafted` includes the first attempt's keys and `failed` excludes them.
  - **Merge test:** it asserts the stored suspension's scope `startedAt` after two suspends with different start times (last writer wins, as designed), reading `Suspensions::all()`.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them, then the full suite.**
- [ ] **Step 5: Commit** — "Follow-ups: one suspension-reason vocabulary, partial sync reports, refs() key name, a stronger merge test, spec wording".

---

### Task 10: Documentation

**Files:** `README.md` (a "Background mode" section: the three language settings, the glossary format, update mode and the cosmetic rule, the cycle steps, the `automation` config, `prosetta:cycle [--sync]`, `prosetta:health`, CI usage, notifications via events), `docs/handoff/2026-09-22-undaunted-adoption.md` (a §12 "Background mode (added 2026-09-23)" with Undaunted's steps from Tasks 11–13), `docs/superpowers/specs/2026-09-23-automation-gaps-and-edge-cases.md` (G3, G4, G5, G9 → Fixed, and G8 → budgets durable, citing the branch; remove the follow-ups §3 items fixed by Task 9 and the estimator note fixed by Task 6).

- [ ] **Step 1:** Write the documents from the code as built. Read the code, not only the spec; documentation must not claim anything the code doesn't do.
- [ ] **Step 2:** `vendor/bin/pest` → green.
- [ ] **Step 3: Commit** — "Document background mode and mark the gaps it closes".

---

### Task 11 (Undaunted): schema, moved notes and the driver

Work in `C:\Websites\undaunted\undaunted-web` on the branch `feat/background-mode`.

**Files:**
- Create: `database/migrations/0050_locales/0050_01_01_000600_add_prosetta_automation_columns.php`: a copy of the package migration, same body, so Undaunted's own migrations own its schema, as for the other Prosetta tables.
- Create: `database/migrations/0050_locales/0050_01_01_000700_move_translation_style_notes.php`: a data migration.
- Modify: `app/Services/Translation/LaravelAiTranslationDriver.php`, `app/Ai/Agents/Translator.php`, `database/seeders/LocalesSeeder.php`
- Delete: `config/translation.php`
- Test: `tests/Feature/Ai/TranslationDriverTest.php` (update and append), `tests/Feature/LocalesSeedTest.php` (append)

**The data migration** writes each language's style note and glossary to its locale row by code. Use the exact note texts from today's `config/translation.php` (read them first) for `es`, `ar` and `zh`. `zh` is stored on the `zh-CN` row, since there's no `zh` row; check `prosetta_locales` for the codes that exist. The glossaries:
- `es`: `[{source: 'locale', target: 'idioma', banned: ['configuración regional']}]`
- `ar`: `[{source: 'locale', target: 'اللغة', banned: []}, {source: 'cohort', target: 'دفعة', banned: ['فوج', 'مجموعة']}, {source: 'class', target: 'دفعة', banned: ['فوج', 'مجموعة']}]`
- `zh-CN`: `[{source: 'locale', target: '语言', banned: ['区域设置', '语言环境']}, {source: 'cohort', target: '班', banned: ['批次', '小组']}, {source: 'class', target: '班', banned: ['批次', '小组']}]`

Remove the `Terms: …` sentences from the notes once the glossary carries them. It also sets `auto_translate` true for es, ar and zh-CN, and false for everything else, including `en_GB`. `down()` restores nothing (a data migration); document that.

**The seeder** gains `auto_translate` in its rows (true for es, ar, zh-CN) and leaves `style_note` and `glossary` alone on re-seed, as it does for `active`/`translated`: read the seeder's "a re-seed only ever touches…" rule and keep it.

**The driver:**
- `style` comes from `$batch->target->styleNote` (the descriptor already falls back regional → language). `'glossary' => $batch->target->glossary` (omit it when empty).
- Each item gains `'previous_source' => $item->previousSource` and, when set, `'change' => SourceChange::diff($item->previousSource, $item->source)`.
- Delete the private `style()` method and the `config('translation.styles')` read.

**The Translator agent's instructions** gain:
```
When an item has previous_source and previous_translation, the English was edited: previous_translation
was made from previous_source, and change shows what changed ([-removed-] {+added+}). Edit
previous_translation only as much as the change requires; keep every other word, phrase and punctuation
mark exactly as it is.
Follow the glossary when given: when an entry's source term appears, use its target and never a banned term.
```

- [ ] **Step 1: Write the failing tests:**
  - **The driver sends the note and glossary from the descriptor:** build a `LocaleDescriptor` with a note and glossary and assert the prompt JSON.
  - **An update item carries `previous_source` and `change`:** a `TranslationItem` with `previousSource`; assert `change` equals `SourceChange::diff(...)`.
  - **No reference to `translation.styles` remains:** assert that `config('translation')` is null.
  - **`LocalesSeedTest`:** after migrating and seeding, es, ar and zh-CN have `auto_translate` true and en_GB false; the es note contains "tú"; the ar glossary has دفعة.

  Update the existing style-note test (which configured `translation.styles`) to set the descriptor instead.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.** Then run `php artisan migrate` on the dev database, and check that `php artisan tinker` shows the notes on the rows.
- [ ] **Step 4:** `php artisan test --parallel` and `vendor/bin/pint --test app config tests database` → green.
- [ ] **Step 5: Commit** — "feat(locales): language notes and glossaries in the database, and minimal updates for edited strings".

---

### Task 12 (Undaunted): Bridge Locales settings

**Files:**
- Modify: `app/Modules/Bridge/Http/Requests/Locales/UpdateLocaleRequest.php`, `app/Modules/Bridge/Http/Controllers/Locales/UpdateController.php` (`FIELDS` gains `auto_translate`, `style_note` and `glossary`, which are audited), `app/Modules/Bridge/Http/Controllers/Locales/IndexController.php` (each row exposes `autoTranslate`, `styleNote`, `glossary` and `isSource`), `resources/js/modules/bridge/lib/types.ts`, `resources/js/modules/bridge/components/LocaleDialog/LocaleDialog.tsx`, `app/Modules/Bridge/Lang/en/locales.php` (the new copy strings)
- Test: `tests/Feature/Modules/Bridge/LocalesSectionTest.php` (append), `resources/js/modules/bridge/components/LocaleDialog/LocaleDialog.test.tsx` (append or create)

**Rules:**
- **Validation:**
  - `auto_translate` is `sometimes|boolean`, and `prohibited` for the source locale (the locale whose code is `config('prosetta.source_locale')`);
  - `style_note` is `sometimes|nullable|string|max:1000`;
  - `glossary` is `sometimes|nullable|array|max:100`;
  - `glossary.*.source` and `glossary.*.target` are `required|string|max:100`;
  - `glossary.*.banned` is `sometimes|array|max:10`;
  - `glossary.*.banned.*` is `string|max:100`.
- **The dialog:**
  - An "Auto-translate" checkbox (hidden for the source locale), placed after "Active" and styled the same way.
  - A "Style note" textarea (hidden for the source locale) with a character count out of 1,000.
  - A "Glossary" list: each row has three inputs (English term, translation, banned alternatives as comma-separated text), plus a remove button, and an "Add term" button. The banned text is split on commas and trimmed into an array on submit.
  - Reuse the dialog's existing form primitives (read `LocaleDialog.tsx` and the `@/components/primitives` it already imports). The copy comes from `bridge::locales` like the other labels.
- **New copy keys** in `app/Modules/Bridge/Lang/en/locales.php`: `form.autoTranslate`, `form.autoTranslateHint` ("Draft new strings for this language automatically."), `form.styleNote`, `form.styleNoteHint` ("Register, tone and regional choices sent with every translation."), `form.glossary`, `form.glossaryHint` ("Required translations for terms, and alternatives never to use."), `form.glossarySource`, `form.glossaryTarget`, `form.glossaryBanned`, `form.glossaryAdd` and `form.glossaryRemove`.

- [ ] **Step 1: Write the failing tests.**
  - **PHP:**
    - the update saves `auto_translate`, `style_note` and `glossary`, and the audit row's before/after include them;
    - validation rejects a 1,001-character note, 101 glossary entries, and an entry without a target;
    - `auto_translate` on the source locale is refused;
    - the index props include the new fields.
  - **Vitest:** the dialog renders the three controls for a target locale and hides them for the source; "Add term" adds a row; submitting sends `glossary` with `banned` split into an array. Mock the Inertia form the way the existing dialog tests do (read them).
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** `php artisan test --parallel`, `npm run -s test:run`, `npm run -s types:check` and `npx vp check` on the changed front-end files → green.
- [ ] **Step 5: Commit** — "feat(bridge): auto-translate, style note and glossary on each locale".

---

### Task 13 (Undaunted): notifications, scheduling and the end-to-end proof

**Files:**
- Create: `app/Notifications/Translation/TranslationAlert.php` (one mail notification class taking a subject and lines), `app/Listeners/Translation/NotifyTranslationAlerts.php` (subscribes to Prosetta's `TranslationHalted`, `BudgetReached` (daily/monthly only), `TranslationSuspended` (reason outage only) and `CycleCompleted` (when `flagged` isn't empty))
- Modify: `app/Providers/AppServiceProvider.php` or `EventServiceProvider` (register the subscriber, following how Undaunted registers listeners), `routes/console.php`, `config/prosetta.php` (the `automation` block with Undaunted's values), `.env.example` (`PROSETTA_ALERTS_TO=`), `tests/Pest.php` (move `capReachedBatch()` there from `TranslationDriverTest.php`)
- Test: `tests/Feature/Translation/TranslationAlertsTest.php`, `tests/Feature/Translation/BackgroundCycleTest.php`

**Rules:**
- **Recipient:** `config('prosetta.alerts_to')`, read from `env('PROSETTA_ALERTS_TO')`. Add it to Undaunted's `config/prosetta.php` as `'alerts_to' => env('PROSETTA_ALERTS_TO')`. When empty, nothing is sent. Send with `Notification::route('mail', $address)->notify(new TranslationAlert($subject, $lines))`.
- **Subjects and lines:** as in spec §8. The `CycleCompleted` digest lists the counts and up to 50 flagged refs.
- **Deduplication:** a `TranslationSuspended` outage mail is sent once per circuit per day, using a cache `add` key `translation-alert:{circuit}:outage:{date}`.
- **Scheduling (`routes/console.php`):**
  - `Schedule::command('queue:prune-batches --hours=48')->daily()->onOneServer();`
  - An hourly health check that runs `prosetta:health`. On a non-zero exit it mails "Translation health check failed" with the output, at most once a day per identical output (a cache `add` on its hash). Implement it as a closure schedule calling `Artisan::call('prosetta:health')` and `Artisan::output()`.
- **`config/prosetta.php`:** `'automation' => ['every' => 30, 'approve' => 'all', 'export' => true, 'rewrite_ratio' => 3.0]`.

- [ ] **Step 1: Write the failing tests.**
  - `TranslationAlertsTest.php` (`Notification::fake()`):
    - each event sends the expected mail to `PROSETTA_ALERTS_TO`;
    - no address → nothing sent;
    - an outage suspension mails once per day;
    - a `CycleCompleted` with no flagged refs sends nothing;
    - a per_run `BudgetReached` sends nothing.
  - `BackgroundCycleTest.php` (end to end, with an array cache store):
    - Seed locales; `Locale` es `auto_translate` true.
    - Point Prosetta at a temp copy of a tiny lang tree. Use Undaunted's real lang paths only if the test can restore them; prefer a temp directory registered with `app()->useLangPath(...)`, like Prosetta's fixture pattern, containing `en/demo.php` with `['greeting' => 'Hello, :name.', 'saved' => 'Saved']` and `es/demo.php` with approved Spanish.
    - Sync.
    - Change `greeting` to `'Hello again, :name.'` and `saved` to `'Saved.'`.
    - `Translator::fake()` returns an updated Spanish for the greeting.
    - Run `php artisan prosetta:cycle --sync`, then assert:
      - exit 0;
      - `saved` was confirmed without a prompt (`Translator::assertPrompted` with exactly one prompt, whose items contain only `greeting`, with `previous_source` and `change` present);
      - the exported `es/demo.php` has the updated greeting;
      - `CycleCompleted` was dispatched.
  - The scheduled commands: `schedule:list` output contains `prosetta:cycle` (every 30 minutes), `queue:prune-batches`, and the hourly health closure.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Run the full Undaunted checks: `php artisan test --parallel`, `npm run -s test:run`, `vendor/bin/pint --test app config tests database routes`, `composer validate --no-check-publish`.
- [ ] **Step 5: Commit** — "feat(locales): translation alerts by mail, a 30-minute background cycle, health checks and batch pruning". Do not merge; the controller merges.
