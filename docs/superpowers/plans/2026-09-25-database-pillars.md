# Database-Driven Pillars Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The twelve pillars move from `App\Enums\Content\Pillar` plus `lang/*/pillars.php` into a `pillars` table. Staff add, edit, reorder, retire and restore pillars on the Bridge and through Artisan. Their names, badges and subtitles are translated through Prosetta content translation.

**Architecture:**
- **Model:** `App\Models\Pillar` uses Prosetta's `TranslatesContent` (Part 1).
- **Service:** `App\Services\Content\PillarCatalogue` makes every change and records it in the Bridge Log. The Bridge controllers and the `pillars:*` commands both call it.
- **Cohorts:** they reference pillars by slug (a foreign key to `pillars.slug`).
- **Seeding:** `PillarsSeeder` seeds the English and imports the existing translations on every seed, so a `migrate:fresh` never loses them.

**Tech Stack:** Laravel 13, PHP 8.4, Pest, Inertia + React 19, Wayfinder, Vitest, Postgres (`undaunted_test_gb` for tests).

**Spec:** `C:\Websites\packages\prosetta\docs\superpowers\specs\2026-09-25-content-translation-and-pillars-design.md` (Part 2). **Depends on:** Prosetta branch `feat/content-translation` (Part 1, unmerged). The Undaunted worktree's `vendor/lonely-lights/prosetta` junction points at `C:\Websites\packages\prosetta-worktrees\content` for this work.

## Global Constraints

- Work in the worktree `C:\Websites\undaunted-worktrees\pillars` (branch `feat/database-pillars` from Undaunted `main`). Never touch the shared checkout. Never `git stash`. Never delete through the vendor junction; unlink it with `cmd /c rmdir`.
- Coordination with the spine session (plan 3, onboarding waves, in progress on `feat/onboarding-spine-3`):
  - plan 3 deletes `Designations::schedule()` and drops `--pillar` from `ScheduleCohortCommand`;
  - this branch changes those minimally, so it compiles on today's main, and takes plan 3's deletions when merging main later;
  - nothing merges to main without the owner's named go.
- Pillars are referenced by `slug`. `cohort_pillars.pillar` becomes a foreign key to `pillars.slug`, with that migration edited in place (pre-launch).
- Pillars are never deleted: `retired_at` hides them from new choices, and existing cohorts keep them.
- Bridge page: Content Array group, gated by the existing `bridge-content-manage` permission (see Ruling R1). The routes sit inside the Bridge group, which already applies `cloak:access-bridge`.
- Console parity: every Bridge action has a `pillars:*` command, recorded in the Bridge Log with a null actor.
- All copy lives in lang files. Only English is added; the Prosetta cycle drafts the other languages.
- Reading a pillar's text for people goes through `translated()`; `$pillar->name` is the English source.

## Rulings made while planning

- **R1:** The spec named a new `pillars.manage` permission. The Content Array already has `bridge-content-manage` ("Badges, categories, tags and regulatory pages on the Bridge."), and the badges page uses it. Pillars are Content Array content, so they use it too. This avoids an `app/Enums` change in the spine session's area. Cost if wrong: one new permission case and its seeder grants later.
- **R2:** The spec said the old `lang/*/pillars.php` files are "removed". Content translations live only in the database, and dev databases get `migrate:fresh` often. So the files move to `database/seeders/Content/pillars/<locale>.php`, and `PillarsSeeder` imports them on each seed. Cost if wrong: four small data files kept in the repo.
- **R3:** `CohortPillar` keeps its `pillar` column (the slug) and gains a `pillarRecord()` relation. A relation named `pillar` would be shadowed by the column. Cost if wrong: a rename.

## Review Focus

1. **Retiring a pillar a cohort uses:** the cohort still shows it; `cohorts:create --pillar=<retired>` refuses it. Pinned in Task 2.
2. **`migrate:fresh --seed`:** all twelve pillars come back with Arabic, Spanish and Chinese names approved, and `translated()` in `es` returns Spanish. Pinned in Task 1.
3. **Changing a pillar's slug while cohorts reference it:** the cohorts follow (cascading update) and the translations follow (Prosetta renames the keys). Pinned in Task 3.
4. **Two staff reordering at once, or a reorder list that omits or repeats a slug:** it's refused, and the order is unchanged. Pinned in Task 3.
5. **A member in Spanish on the Bridge cohorts page:** pillar labels show in Spanish. Pinned in Task 2.

---

### Task 1: The pillars table, model, factory and seeder with translations

**Files:**
- Create: `database/migrations/0080_content/0080_01_01_000100_create_pillars_table.php`
- Create: `app/Models/Pillar.php`
- Create: `database/factories/PillarFactory.php`
- Create: `database/seeders/Content/PillarsSeeder.php`
- Create: `database/seeders/Content/pillars/en.php`, `ar.php`, `es.php` and `zh-CN.php`, moved from `lang/<locale>/pillars.php` (`git mv`)
- Modify: `database/seeders/DatabaseSeeder.php` (call `PillarsSeeder` after `LocalesSeeder`, before `CohortsSeeder`)
- Delete: `lang/en_GB/pillars.php`, if it exists (a derived file that the cycle no longer needs)
- Test: `tests/Feature/Content/PillarsTest.php`

**Interfaces:**
- Produces:
  - `App\Models\Pillar`, with properties slug, name, badge, subtitle, sort and retired_at;
  - scopes `active()` and `ordered()`;
  - `isRetired(): bool`;
  - `translatableFields()`;
  - `getRouteKeyName(): 'slug'`;
  - `PillarsSeeder::SLUGS` (the twelve founding slugs, in order);
  - `Pillar::factory()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\Pillar;
use Database\Seeders\Content\PillarsSeeder;
use Database\Seeders\LocalesSeeder;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(PillarsSeeder::class);
});

it('seeds the twelve founding pillars in order, keyed by slug', function () {
    expect(Pillar::query()->ordered()->pluck('slug')->all())->toBe(PillarsSeeder::SLUGS)
        ->and(Pillar::query()->where('slug', 'arts-culture')->value('name'))->toBe('Arts and Cultural Heritage')
        ->and(Pillar::query()->where('slug', 'scientific-advancements')->value('badge'))->toBe('Science');
});

it('brings the existing translations with it, so a fresh database is never English-only', function () {
    $pillar = Pillar::query()->where('slug', 'technology')->firstOrFail();
    $spanish = require database_path('seeders/Content/pillars/es.php');

    expect($pillar->translated('name', 'es'))->toBe($spanish['technology']['name'])
        ->and($pillar->translated('subtitle', 'ar'))->not->toBe($pillar->subtitle);
});

it('seeds again without duplicating or clobbering staff edits', function () {
    Pillar::query()->where('slug', 'technology')->update(['name' => 'Technology and Tools']);

    $this->seed(PillarsSeeder::class);

    expect(Pillar::query()->count())->toBe(12)
        ->and(Pillar::query()->where('slug', 'technology')->value('name'))->toBe('Technology and Tools');
});

it('lists only pillars that are not retired as active', function () {
    Pillar::query()->where('slug', 'education')->update(['retired_at' => now()]);

    expect(Pillar::query()->active()->count())->toBe(11)
        ->and(Pillar::query()->where('slug', 'education')->first()->isRetired())->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Content/PillarsTest.php`
Expected: FAIL. Class `App\Models\Pillar` is not found.

- [ ] **Step 3: Implement**

Migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The pillars of the community: edited on the Bridge, translated through Prosetta, retired rather than deleted. */
return new class extends Migration {
    public function up(): void {
        Schema::create('pillars', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 120);
            $table->string('badge', 40);
            $table->string('subtitle', 300);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['retired_at', 'sort']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('pillars');
    }
};
```

Model:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PillarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Content\TranslatesContent;

/**
 * One pillar of the community. Its English lives here; every other language
 * is Prosetta content (content::pillars.<slug>.<field>), read through translated().
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $badge
 * @property string $subtitle
 * @property int $sort
 * @property Carbon|null $retired_at
 */
#[Fillable(['slug', 'name', 'badge', 'subtitle', 'sort'])]
class Pillar extends Model {
    /** @use HasFactory<PillarFactory> */
    use HasFactory, TranslatesContent;

    public function getRouteKeyName(): string {
        return 'slug';
    }

    /** @return array<string, string> */
    public function translatableFields(): array {
        return [
            'name' => 'The full name of one of the pillars of the Undaunted community, such as "Arts and Cultural Heritage".',
            'badge' => 'A one- or two-word short name for the pillar, shown on a small badge.',
            'subtitle' => 'One sentence on how this pillar serves the whole community, on Earth and beyond it.',
        ];
    }

    public function translationMaxLength(string $field): ?int {
        return match ($field) {
            'badge' => 40,
            'name' => 120,
            default => 300,
        };
    }

    public function isRetired(): bool {
        return $this->retired_at !== null;
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void {
        $query->whereNull('retired_at');
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void {
        $query->orderBy('sort')->orderBy('id');
    }

    protected function casts(): array {
        return ['sort' => 'integer', 'retired_at' => 'datetime'];
    }
}
```

Factory: `slug` is a unique `fake()->unique()->slug(2)`, and `name`, `badge` and `subtitle` are short fake words; `sort` is 0.

Seeder:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders\Content;

use App\Models\Pillar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;

/**
 * The twelve founding pillars. Creates any that are missing (never
 * overwrites a staff edit), then imports each maintained language's
 * translations, so a fresh database is never English-only.
 */
final class PillarsSeeder extends Seeder {
    public const array SLUGS = [
        'arts-culture', 'economics-finance', 'education', 'engineering', 'environment', 'exploration-discovery',
        'food-agriculture', 'health-longevity', 'scientific-advancements', 'social-equity', 'sustainability', 'technology',
    ];

    public function run(LocaleSource $locales): void {
        /** @var array<string, array{name: string, badge: string, subtitle: string}> $english */
        $english = require __DIR__.'/pillars/en.php';

        foreach (self::SLUGS as $index => $slug) {
            Pillar::query()->firstOrCreate(['slug' => $slug], [...$english[$slug], 'sort' => $index + 1]);
        }

        foreach ($locales->targets() as $locale) {
            $path = __DIR__."/pillars/{$locale->code}.php";

            if (is_file($path)) {
                Artisan::call('prosetta:content:import', ['folder' => 'pillars', 'locale' => $locale->code, 'path' => $path]);
            }
        }
    }
}
```

The moved data files keep their current contents: `slug => [name, badge, subtitle]`. Check that each is plain `return [...]`, with no lang-only helpers.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Content/PillarsTest.php`
Expected: 4 passed. If the translation test fails because the import ran before the keys existed, check that the seeder's `firstOrCreate` is outside any transaction, since `TranslatesContent` syncs after commit.

- [ ] **Step 5: Commit**

```bash
git add database app/Models/Pillar.php tests/Feature/Content/PillarsTest.php lang
git commit -m "Pillars live in a table, translated through Prosetta, seeded with their translations"
```

---

### Task 2: Cohorts reference pillars by slug

**Files:**
- Modify: `app/Modules/Cohorts/Database/Migrations/0400_01_01_000110_create_cohort_pillars_table.php` (a foreign key to `pillars.slug`, `cascadeOnUpdate`, `restrictOnDelete`)
- Modify: `app/Modules/Cohorts/Models/CohortPillar.php` (drop the enum cast; add `pillarRecord()`)
- Modify: `app/Modules/Cohorts/Models/Cohort.php` (`pillarValues()` returns `list<Pillar>` models)
- Modify: `app/Modules/Cohorts/Services/Cohorts/CohortCreator.php` (`list<Pillar>`; refuses retired pillars)
- Modify: `app/Modules/Cohorts/Services/Cohorts/Designations.php` (the `schedule()` pillar loop uses `->slug`; minimal, since plan 3 deletes it)
- Modify: `app/Modules/Cohorts/Console/Concerns/ReadsPillarOption.php` (reads active pillars from the table)
- Modify: `app/Modules/Cohorts/Console/CreateCohortCommand.php` (audit context maps `->slug`)
- Modify: `app/Modules/Cohorts/Http/Controllers/Bridge/Cohorts/IndexController.php` (label via `translated('name')`; eager-load `pillars.pillarRecord`)
- Modify: `app/Modules/Cohorts/README.md` (the Pillar references)
- Modify: `resources/js/types/content.ts` (`PillarValue = string`, documented)
- Delete: `app/Enums/Content/Pillar.php`, `tests/Unit/Enums/Content/PillarTest.php`
- Modify: the Cohorts tests that use the enum (`CapabilitiesTest`, `CohortCommandsTest`, `CohortModelTest`, `CohortsSectionTest`, `DesignationsTest`, `ScheduleCohortCommandTest`): seed `PillarsSeeder` in `beforeEach`, and replace `Pillar::Technology` with `Pillar::query()->where('slug', 'technology')->firstOrFail()` through a `pillar(string $slug): Pillar` helper in `tests/Pest.php`
- Test: `tests/Feature/Content/PillarReferencesTest.php`

**Interfaces:**
- Consumes: `Pillar`, `PillarsSeeder` (Task 1).
- Produces:
  - `CohortPillar::pillarRecord(): BelongsTo<Pillar>`;
  - `Cohort::pillarValues(): list<Pillar>`;
  - `CohortCreator::create(string $name, list<Pillar> $pillars)`, which throws `InvalidArgumentException` on a retired pillar;
  - the test helper `pillar(string $slug): Pillar`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\Pillar;
use App\Modules\Cohorts\Services\Cohorts\CohortCreator;
use Database\Seeders\Content\PillarsSeeder;
use Database\Seeders\LocalesSeeder;
use Illuminate\Database\QueryException;
use LonelyLights\Prosetta\Review\ReviewService;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(PillarsSeeder::class);
});

it('keeps a retired pillar on the cohorts that have it, but refuses it for new ones', function () {
    $cohort = app(CohortCreator::class)->create('Makers', [pillar('technology')]);
    pillar('technology')->forceFill(['retired_at' => now()])->save();

    expect($cohort->fresh()->pillarValues()[0]->slug)->toBe('technology')
        ->and(fn () => app(CohortCreator::class)->create('Tinkerers', [pillar('technology')]))->toThrow(InvalidArgumentException::class);

    $this->artisan('cohorts:create', ['name' => 'Builders', '--pillar' => ['technology']])->assertExitCode(2);
});

it('refuses a cohort pillar that is not in the table', function () {
    $cohort = app(CohortCreator::class)->create('Makers', [pillar('technology')]);

    expect(fn () => $cohort->pillars()->create(['pillar' => 'astrology']))->toThrow(QueryException::class);
});

it('labels a cohort\'s pillars in the reader\'s language on the Bridge', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología (revisada)', null, approve: true);
    $cohort = app(CohortCreator::class)->create('Makers', [pillar('technology')]);
    $crew = crewWithPermission('bridge-cohorts-view');

    app()->setLocale('es');
    $this->actingAs($crew)->withSession(['locale' => 'es'])->get('/bridge/cohorts')
        ->assertInertia(fn ($page) => $page->where('table.rows.0.pillars.0.label', 'Tecnología (revisada)'));
});
```

`crewWithPermission()` stands for however `CohortsSectionTest` builds a crew member who can open the Cohorts page. Copy that setup exactly: permission name, locale session key or `SetLocale` input, and the cohorts route. Rule on any mismatch in the ledger.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Content/PillarReferencesTest.php`
Expected: FAIL. The cohorts code still expects the enum.

- [ ] **Step 3: Implement**

Migration (edited in place):

```php
$table->string('pillar', 40);
$table->foreign('pillar')->references('slug')->on('pillars')->cascadeOnUpdate()->restrictOnDelete();
```

`CohortPillar`: remove the `casts()` pillar entry and the enum import. Set `@property string $pillar` and add:

```php
    /** The pillar itself; the column holds its slug. @return BelongsTo<Pillar, $this> */
    public function pillarRecord(): BelongsTo {
        return $this->belongsTo(Pillar::class, 'pillar', 'slug');
    }
```

`Cohort::pillarValues()`:

```php
    /** @return list<Pillar> */
    public function pillarValues(): array {
        return array_values($this->pillars->loadMissing('pillarRecord')->map(fn(CohortPillar $row): Pillar => $row->pillarRecord)->all());
    }
```

`CohortCreator::create()`: after the empty check, refuse retired pillars:

```php
        foreach ($pillars as $pillar) {
            if ($pillar->isRetired()) {
                throw new InvalidArgumentException("The pillar [$pillar->slug] is retired.");
            }
        }
```

The create loop becomes `foreach (array_unique(array_map(fn(Pillar $pillar): string => $pillar->slug, $pillars)) as $slug) { CohortPillar::query()->create(['cohort_id' => $cohort->id, 'pillar' => $slug]); }`. The same loop change goes into `Designations::schedule()`, and its `@param` becomes `list<Pillar>` with `App\Models\Pillar` imported.

`ReadsPillarOption::pillarsOption()` returns `list<Pillar>|null`:

```php
        foreach ((array) $this->option('pillar') as $value) {
            $pillar = is_string($value) ? Pillar::query()->active()->where('slug', trim($value))->first() : null;

            if ($pillar === null) {
                $this->error('Unknown pillar "'.(is_string($value) ? $value : '').'". Use one of: '
                    .implode(', ', Pillar::query()->active()->ordered()->pluck('slug')->all()).'.');

                return null;
            }

            $pillars[] = $pillar;
        }
```

`CreateCohortCommand`: `'pillars' => array_map(fn(Pillar $pillar): string => $pillar->slug, $cohort->pillarValues())`, with the import switched to `App\Models\Pillar`.

Bridge cohorts `IndexController`: eager-load `'pillars.pillarRecord'`, and map each row to `['value' => $row->pillar, 'label' => $row->pillarRecord?->translated('name') ?? $row->pillar]`.

`resources/js/types/content.ts`:

```ts
/**
 * A pillar's slug, as the server sends it. Pillars live in the database and
 * staff can add more, so this is any string; the mediums registry names the
 * founding twelve, and PillarReferencesTest checks those against the seeder.
 */
export type PillarValue = string;
```

Add to `tests/Pest.php`: `function pillar(string $slug): App\Models\Pillar { return App\Models\Pillar::query()->where('slug', $slug)->firstOrFail(); }`.

Update the listed Cohorts tests to seed `PillarsSeeder` (after `LocalesSeeder`, where they seed locales) and use `pillar('…')`. In `PillarReferencesTest`, add a test that every `pillar: '…'` slug in `resources/js/modules/cohorts/components/Mediums/mediums.ts` is in `PillarsSeeder::SLUGS`. It reads the file the way the old PillarTest read the union.

Update the README's pillar references to say that pillars are rows in `pillars`, referenced by slug.

- [ ] **Step 4: Run the tests**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Content tests/Feature/Modules/Cohorts`, then `npx vitest run resources/js/modules/cohorts` and `npm run types:check`.
Expected: all pass. `grep -rn "Enums\\\\Content\\\\Pillar" app tests resources` finds nothing.

- [ ] **Step 5: Commit**

```bash
git add -A app tests resources/js database
git commit -m "Cohorts reference database pillars by slug; retired pillars stay but can't be chosen"
```

---

### Task 3: PillarCatalogue and the pillars:* commands (console parity)

**Files:**
- Create: `app/Services/Content/PillarCatalogue.php`
- Create: `app/Console/Commands/Pillars/AddPillarCommand.php`, `UpdatePillarCommand.php`, `RetirePillarCommand.php`, `RestorePillarCommand.php`, `MovePillarCommand.php` and `ListPillarsCommand.php`. Confirm that `bootstrap/app.php` discovers `app/Console/Commands`; if it doesn't, register them in `AppServiceProvider` the way modules do, and ledger the ruling.
- Test: `tests/Feature/Content/PillarCatalogueTest.php`

**Interfaces:**
- Consumes: `Pillar` (Task 1); `App\Contracts\Bridge\Auditor::record(string $action, ?Model $subject, array $before = [], array $after = [], array $context = [])`.
- Produces:
  - `PillarCatalogue::add(string $slug, string $name, string $badge, string $subtitle): Pillar`, placed last;
  - `update(Pillar $pillar, array{slug?: string, name?: string, badge?: string, subtitle?: string} $changes): Pillar`;
  - `retire(Pillar $pillar): Pillar`;
  - `restore(Pillar $pillar): Pillar`;
  - `reorder(list<string> $slugs): void`, which throws `InvalidArgumentException` unless the list is exactly every pillar's slug once.
- Every method records `pillars.add`, `pillars.update`, `pillars.retire`, `pillars.restore` or `pillars.reorder` through the Auditor when one is bound (the actor comes from the Auditor's own request context; the console gives null).
- Commands:
  - `pillars:add {slug} {name} {--badge=} {--subtitle=}`;
  - `pillars:update {slug} {--slug=} {--name=} {--badge=} {--subtitle=}`;
  - `pillars:retire {slug}`;
  - `pillars:restore {slug}`;
  - `pillars:move {slug} {position}`, where position is 1-based;
  - `pillars:list`.
- Exit codes follow the house pattern: 0 on success, 2 (`INVALID`) for a bad slug or input.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Models\Pillar;
use App\Services\Content\PillarCatalogue;
use App\Modules\Cohorts\Services\Cohorts\CohortCreator;
use App\Modules\Bridge\Models\AuditEntry;
use Database\Seeders\Content\PillarsSeeder;
use Database\Seeders\LocalesSeeder;
use LonelyLights\Prosetta\Review\ReviewService;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(PillarsSeeder::class);
});

it('adds a pillar last, and records it in the Bridge Log', function () {
    $pillar = app(PillarCatalogue::class)->add('space-law', 'Space Law and Governance', 'Law', 'How we govern ourselves beyond Earth.');

    expect($pillar->sort)->toBe(13)
        ->and(AuditEntry::query()->where('action', 'pillars.add')->exists())->toBeTrue();
});

it('carries cohorts and translations along when a slug changes', function () {
    $cohort = app(CohortCreator::class)->create('Makers', [pillar('technology')]);
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);

    app(PillarCatalogue::class)->update(pillar('technology'), ['slug' => 'tech']);

    expect($cohort->fresh()->pillarValues()[0]->slug)->toBe('tech')
        ->and(pillar('tech')->translated('name', 'es'))->toBe('Tecnología');
});

it('reorders only when given every pillar exactly once', function () {
    $slugs = PillarsSeeder::SLUGS;
    $catalogue = app(PillarCatalogue::class);

    expect(fn () => $catalogue->reorder(array_slice($slugs, 1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $catalogue->reorder([...$slugs, 'technology']))->toThrow(InvalidArgumentException::class);

    $catalogue->reorder(array_reverse($slugs));

    expect(Pillar::query()->ordered()->pluck('slug')->all())->toBe(array_reverse($slugs));
});

it('retires and restores through the console, logged like the Bridge', function () {
    $this->artisan('pillars:retire', ['slug' => 'education'])->assertExitCode(0);
    expect(pillar('education')->isRetired())->toBeTrue();

    $this->artisan('pillars:restore', ['slug' => 'education'])->assertExitCode(0);
    expect(pillar('education')->isRetired())->toBeFalse()
        ->and(AuditEntry::query()->whereIn('action', ['pillars.retire', 'pillars.restore'])->count())->toBe(2);
});

it('refuses an unknown slug or a duplicate one from the console', function () {
    $this->artisan('pillars:retire', ['slug' => 'astrology'])->assertExitCode(2);
    $this->artisan('pillars:add', ['slug' => 'technology', 'name' => 'Again', '--badge' => 'Tech', '--subtitle' => 'Again.'])->assertExitCode(2);
});

it('moves a pillar to a position', function () {
    $this->artisan('pillars:move', ['slug' => 'technology', 'position' => 1])->assertExitCode(0);

    expect(Pillar::query()->ordered()->value('slug'))->toBe('technology');
});
```

`AuditEntry` stands for the Bridge Log's model. Read `app/Contracts/Bridge/Auditor.php`'s binding for the real class name and column (`action`), and adjust the import. That is not a ruling unless the column differs.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Content/PillarCatalogueTest.php`
Expected: FAIL. Class `App\Services\Content\PillarCatalogue` is not found.

- [ ] **Step 3: Implement the service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Contracts\Bridge\Auditor;
use App\Models\Pillar;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Every change to the pillars, from the Bridge or the console, goes through
 * here, so each is validated the same way and lands in the Bridge Log once.
 */
final readonly class PillarCatalogue {
    private const array FIELDS = ['slug', 'name', 'badge', 'subtitle', 'sort', 'retired_at'];

    public function __construct(private Container $container) {}

    public function add(string $slug, string $name, string $badge, string $subtitle): Pillar {
        if (Pillar::query()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException("A pillar [$slug] already exists.");
        }

        $pillar = Pillar::query()->create([
            'slug' => $slug, 'name' => $name, 'badge' => $badge, 'subtitle' => $subtitle,
            'sort' => (int) Pillar::query()->max('sort') + 1,
        ]);
        $this->record('pillars.add', $pillar, [], $pillar->only(self::FIELDS));

        return $pillar;
    }

    /** @param array{slug?: string, name?: string, badge?: string, subtitle?: string} $changes */
    public function update(Pillar $pillar, array $changes): Pillar {
        if (isset($changes['slug']) && $changes['slug'] !== $pillar->slug && Pillar::query()->where('slug', $changes['slug'])->exists()) {
            throw new InvalidArgumentException("A pillar [{$changes['slug']}] already exists.");
        }

        $before = $pillar->only(self::FIELDS);
        $pillar->update($changes);
        $this->record('pillars.update', $pillar, $before, $pillar->only(self::FIELDS));

        return $pillar;
    }

    public function retire(Pillar $pillar): Pillar {
        return $this->setRetired($pillar, now(), 'pillars.retire');
    }

    public function restore(Pillar $pillar): Pillar {
        return $this->setRetired($pillar, null, 'pillars.restore');
    }

    /**
     * @param list<string> $slugs every pillar's slug, once, in the new order
     * @throws Throwable when the transaction cannot commit
     */
    public function reorder(array $slugs): void {
        $current = Pillar::query()->ordered()->pluck('slug')->all();

        if (count($slugs) !== count(array_unique($slugs)) || array_diff($current, $slugs) !== [] || array_diff($slugs, $current) !== []) {
            throw new InvalidArgumentException('A new order must name every pillar exactly once.');
        }

        DB::transaction(function () use ($slugs): void {
            foreach ($slugs as $index => $slug) {
                Pillar::query()->where('slug', $slug)->update(['sort' => $index + 1]);
            }
        });

        $this->record('pillars.reorder', null, ['order' => $current], ['order' => $slugs]);
    }

    private function setRetired(Pillar $pillar, mixed $at, string $action): Pillar {
        $before = $pillar->only(self::FIELDS);
        $pillar->forceFill(['retired_at' => $at])->save();
        $this->record($action, $pillar, $before, $pillar->only(self::FIELDS));

        return $pillar;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function record(string $action, ?Pillar $pillar, array $before, array $after): void {
        try {
            $this->container->make(Auditor::class)->record($action, $pillar, $before, $after);
        } catch (BindingResolutionException) {
            # No Bridge on This Site: the Change Stands, Unlogged, Exactly as the Cohorts Commands Behave
        }
    }
}
```

- [ ] **Step 4: Implement the commands**

Each command is `final`, and follows `CreateCohortCommand`'s layout: a signature, a description, and `handle(PillarCatalogue $catalogue): int`. It looks the slug up with `Pillar::query()->where('slug', …)->first()` and prints `Unknown pillar "x".` then returns `self::INVALID` when missing. It catches `InvalidArgumentException`, prints its message and returns `self::INVALID`. On success it prints one line, e.g. `Retired education.`.
- `pillars:add` requires `--badge` and `--subtitle`; without them it prints `Pass --badge and --subtitle.` and returns INVALID.
- `pillars:move` builds the new slug order by taking the slug out and putting it back at `position - 1`, clamped to the list, then calls `reorder()`.
- `pillars:list` prints a table of sort, slug, name, badge and a retired marker.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Content`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Content app/Console tests/Feature/Content/PillarCatalogueTest.php bootstrap app/Providers
git commit -m "PillarCatalogue and pillars:* commands: every change validated once and in the Bridge Log"
```

---

### Task 4: The Bridge Pillars page

**Files:**
- Create: `app/Modules/Bridge/Sections/PillarsSection.php` (ContentArray, slug `pillars`, icon `columns-3`, permission `PermissionName::BridgeContentManage`, route `bridge.pillars.index`, order 5)
- Modify: the Bridge provider's section registration (wherever `LocalesSection` is registered)
- Create: `app/Modules/Bridge/Http/Controllers/Pillars/IndexController.php`, `StoreController.php`, `UpdateController.php`, `ReorderController.php`, `RetireController.php` and `RestoreController.php`
- Create: `app/Modules/Bridge/Http/Requests/Pillars/StorePillarRequest.php`, `UpdatePillarRequest.php` and `ReorderPillarsRequest.php`
- Modify: `app/Modules/Bridge/Routes/web.php` (a `pillars` group under `can:bridge-content-manage`, inside the cloaked Bridge group; `{pillar:slug}` binding)
- Create: `app/Modules/Bridge/Lang/en/pillars.php` (all page copy)
- Create: `resources/js/modules/bridge/pages/Pillars/Index.tsx`, `resources/js/modules/bridge/components/PillarDialog/PillarDialog.tsx`, the types in `resources/js/modules/bridge/lib/types.ts` (`PillarRow`, `PillarsIndexPage`) and the fixtures in the Bridge fixtures file
- Test: `tests/Feature/Modules/Bridge/PillarsTest.php`, `resources/js/modules/bridge/pages/Pillars/Index.test.tsx`

**Interfaces:**
- Consumes: `PillarCatalogue` (Task 3); `BridgeShell::props($user, 'pillars')`; `TableQuery` (as the badges and locales pages use it); `Toast::success()`.
- Produces:
  - `GET /bridge/pillars` (`bridge.pillars.index`) with props `table`, where each row is `{slug, name, badge, subtitle, sort, retired, translations: {name: string|null}}`, plus `copy`;
  - `POST /bridge/pillars` (store);
  - `PATCH /bridge/pillars/{pillar:slug}` (update);
  - `POST /bridge/pillars/reorder` (`{order: string[]}`);
  - `POST /bridge/pillars/{pillar:slug}/retire` and `/restore`.

- [ ] **Step 1: Write the failing feature tests**

```php
<?php

declare(strict_types=1);

use App\Models\Pillar;
use Database\Seeders\Content\PillarsSeeder;
use Database\Seeders\LocalesSeeder;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(PillarsSeeder::class);
    $this->editor = crewWithPermission('bridge-content-manage');
});

it('lists every pillar in order, retired ones marked', function () {
    pillar('education')->forceFill(['retired_at' => now()])->save();

    $this->actingAs($this->editor)->get('/bridge/pillars')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bridge::Pillars/Index')
            ->has('table.rows', 12)
            ->where('table.rows.0.slug', 'arts-culture')
            ->where('table.rows.2.retired', true)
            ->has('copy.title'));
});

it('adds, edits, retires, restores and reorders from the Bridge', function () {
    $this->actingAs($this->editor)->post('/bridge/pillars', ['slug' => 'space-law', 'name' => 'Space Law', 'badge' => 'Law', 'subtitle' => 'How we govern ourselves.'])->assertRedirect();
    $this->actingAs($this->editor)->patch('/bridge/pillars/space-law', ['name' => 'Space Law and Governance'])->assertRedirect();
    $this->actingAs($this->editor)->post('/bridge/pillars/space-law/retire')->assertRedirect();
    expect(pillar('space-law')->isRetired())->toBeTrue();
    $this->actingAs($this->editor)->post('/bridge/pillars/space-law/restore')->assertRedirect();
    $this->actingAs($this->editor)->post('/bridge/pillars/reorder', ['order' => array_reverse([...PillarsSeeder::SLUGS, 'space-law'])])->assertRedirect();

    expect(pillar('space-law')->name)->toBe('Space Law and Governance')
        ->and(Pillar::query()->ordered()->value('slug'))->toBe('space-law');
});

it('refuses an invalid or duplicate slug and an incomplete order', function () {
    $this->actingAs($this->editor)->post('/bridge/pillars', ['slug' => 'Not A Slug', 'name' => 'x', 'badge' => 'x', 'subtitle' => 'x'])->assertSessionHasErrors('slug');
    $this->actingAs($this->editor)->post('/bridge/pillars', ['slug' => 'technology', 'name' => 'x', 'badge' => 'x', 'subtitle' => 'x'])->assertSessionHasErrors('slug');
    $this->actingAs($this->editor)->post('/bridge/pillars/reorder', ['order' => ['technology']])->assertSessionHasErrors('order');
});

it('hides the page from crew without content access', function () {
    $this->actingAs(crewWithPermission('access-bridge'))->get('/bridge/pillars')->assertForbidden();
});
```

Build `crewWithPermission()` the way `tests/Feature/Modules/Engagement/Bridge/BadgesTest.php` builds its badge editor. Every Bridge user needs `access-bridge`, plus the named permission. Keep the helper local to this file unless one already exists.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/PillarsTest.php`
Expected: FAIL with a 404. The route doesn't exist yet.

- [ ] **Step 3: Implement the server side**

The controllers are thin: validate through the request, call `PillarCatalogue`, then `Toast::success(__('bridge::pillars.<action>'))` and `back()`. `InvalidArgumentException` from the catalogue becomes `ValidationException::withMessages([field => message])`.

Requests:
- `slug`: `required|string|max:40|regex:/^[a-z]+(-[a-z]+)*$/|unique:pillars,slug` (update: `sometimes` plus `Rule::unique('pillars','slug')->ignore($pillar->id)`);
- `name`: `required|string|max:120`;
- `badge`: `required|string|max:40`;
- `subtitle`: `required|string|max:300`;
- reorder: `order` is `required|array` and `order.*` is `string|distinct`.

`IndexController` mirrors the badges one:
- the query is `TableQuery::for(Pillar::query(), $request, searchable: ['slug','name','badge','subtitle'], sortable: ['sort','name','slug'], defaultSort: 'sort')`;
- rows carry `translations.name` = `$pillar->translated('name')`, so a Spanish-reading editor sees the Spanish beside the English;
- `copy` merges the shell's copy with `trans('bridge::pillars')`.

Routes, inside the existing cloaked `can:access-bridge` group:

```php
    # Content Array: the Pillars, Edited Here and Through pillars:* on the Console
    Route::middleware('can:bridge-content-manage')->prefix('pillars')->name('pillars.')->group(function (): void {
        Route::get('/', PillarsIndexController::class)->name('index');
        Route::post('/', PillarsStoreController::class)->name('store');
        Route::post('/reorder', PillarsReorderController::class)->name('reorder');
        Route::patch('/{pillar:slug}', PillarsUpdateController::class)->name('update');
        Route::post('/{pillar:slug}/retire', PillarsRetireController::class)->name('retire');
        Route::post('/{pillar:slug}/restore', PillarsRestoreController::class)->name('restore');
    });
```

`Lang/en/pillars.php` holds:
- `title` "Pillars";
- `description` "The pillars cohorts relate to: add, edit, reorder and retire them.";
- `subtitle` "Names, badges and subtitles are written in English here; their translations are reviewed in Translations.";
- `columns` (`name`, `badge`, `subtitle`, `status`);
- `status` (`active` "Active", `retired` "Retired");
- `actions` (`add`, `edit`, `retire`, `restore`, `moveUp`, `moveDown`);
- `dialog` (`addTitle`, `editTitle`, the field labels, `slugHint` "Lowercase words joined by hyphens; used in links and by cohorts.", `save`, `cancel`);
- `retireConfirm` "Retire :name? Cohorts that have it keep it, but no new cohort can choose it.";
- the toasts (`added`, `updated`, `retired`, `restored`, `reordered`).

Register `PillarsSection` wherever `LocalesSection` is registered (the Bridge provider's section list).

Run `php artisan wayfinder:generate --with-form`.

- [ ] **Step 4: Write the failing page test, then the page**

`Index.test.tsx` renders the page with fixture props (two rows, one retired) and checks four things:
- the rows show English names, with a retired badge on the retired one;
- "Add pillar" opens the dialog, and submitting posts to `store` with the typed values (mock `router.post`, like the Locales tests do);
- a retired row offers Restore, not Retire;
- the move buttons post the full reordered slug list to `reorder`.

Run: `npx vitest run resources/js/modules/bridge/pages/Pillars`
Expected: FAIL. The module is not found.

The page follows `pages/Locales/Index.tsx`:
- `BridgeLayout`;
- a `DataTable` with columns name (English, with the current-locale translation muted beneath when it differs), badge, subtitle and status;
- row actions: edit, retire or restore (retire behind a `ConfirmAction` with `copy.retireConfirm`), move up and move down (enabled only when the table is unsearched and sorted by `sort`, as Locales does);
- an "Add pillar" button that opens `PillarDialog` in add mode.

`PillarDialog` is a form with slug, name, badge and subtitle, showing the server's validation errors under each field.

- [ ] **Step 5: Run everything**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --parallel --compact`, `npx vitest run`, `npm run types:check`, `npx vp check`, `vendor/bin/pint --test` and `composer types:check`.
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add -A app resources tests
git commit -m "Bridge Pillars page: add, edit, reorder, retire and restore in the Content Array"
```
