# Bridge Translations Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Undaunted's crew a Translations section in Bridge with three pages:
- an **Overview** (cards or a table);
- a **Review** queue (a table with batch actions, or a one-item focus mode);
- a **Keys** browser (a matrix, a file editor, or search).

The pages sit on Prosetta's merged review core.

**Architecture:** Thin Laravel controllers in Undaunted's Bridge module call Prosetta's core (`Viewer`, `ReviewQueue::for`, `KeyBrowser`, `Coverage`, `ReviewDesk` and `ReviewService`) and pass the results' `toArray()` to Inertia/React pages.
- **Writes:** every write goes through one helper. It records a Bridge audit entry, shows a toast, and turns Prosetta's exceptions into responses a person understands.
- **Views:** each page's view is a client-side switch, remembered per person in `localStorage`.
- **Copy:** all copy lives in `bridge::translations`.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 2 with React 19 and TypeScript, Wayfinder route helpers, Pest 5 feature tests, Vitest with happy-dom and Testing Library. The data comes from the `lonely-lights/prosetta` package, reached through Undaunted's vendor link.

**Spec:** `docs/superpowers/specs/2026-09-24-translation-review-design.md`, §3–§7. That's in the Prosetta repo, where this plan lives too. The code goes in the Undaunted repo.

## Global Constraints

- **Where to work:** in the git worktree `C:\Websites\undaunted-worktrees\bridge-translations`, on branch `feat/bridge-translations`, cut from Undaunted `main`.
  - Never check out branches in `C:\Websites\undaunted\undaunted-web`. Another session works there.
  - Set the worktree up with `cp ../../undaunted/undaunted-web/.env .env`, then `composer install` and `npm ci`.
  - `vendor/lonely-lights/prosetta` in the worktree is a link to the live package. Never delete through it. Before removing the worktree, unlink it with `cmd /c rmdir`.
- **Tests:** always run PHP tests as `DB_DATABASE=undaunted_test_gb php artisan test --compact …`. The shared `undaunted_test` database belongs to the other session. Create `undaunted_test_gb` once, if it's missing:

  ```
  php artisan tinker --execute "DB::statement('CREATE DATABASE undaunted_test_gb')"
  ```

- **Route helpers:** after adding routes, run `php artisan wayfinder:generate` before Vitest or `npm run -s types:check`. The generated `resources/js/routes/**` files aren't committed.
- **Areas to stay out of:** don't touch the Cohorts or Identity modules, the users migration, `resources/js/modules/cohorts`, `app/Enums` or `resources/js/types`.
  - Put the new page types in `resources/js/modules/bridge/pages/Translations/types.ts`, not the shared `lib/types.ts`.
  - Put the test fixtures in `pages/Translations/fixtures.ts`.
- **Git:** never run `git stash`. Commit after every task, ending the message with:

  ```
  Co-Authored-By: <model that wrote it> <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2
  ```

- **Copy:** every visible string lives in `app/Modules/Bridge/Lang/en/translations.php`, loaded as `bridge::translations`. No English in TSX.
- **The access gate:** the section and all its routes use the gate `translations.access`. It's true when the user can translate at least one language or can manage (`Viewer::for($user)->locales() !== [] || ->manages`). Founder and System Operator pass through the existing SuperAdmin override.
- **Editable:** `prosetta.review.editable` is off in the `testing` environment. PHP tests that write must set `config(['prosetta.review.editable' => true])` first. Pages receive `viewer.editable` and disable every action when it's false.
- **Prosetta's single approval doesn't throw on a conflict.** `ReviewService::approve()` records `skipped[id] = 'conflict'`, and `ReviewDesk::approveMany` counts it in `conflicts`. Controllers must check the counts and show "changed since you opened it" instead of "approved".
- **Paging:** pages of 50 rows. Keys search is capped at `KeyBrowser::SEARCH_LIMIT` (500).
- **Suite after each task:** `DB_DATABASE=undaunted_test_gb php artisan test --compact --parallel` ends green, apart from the 2 known skips. After a task touching TSX, also run `npx vitest run resources/js/modules/bridge/pages/Translations`, `npm run -s types:check` and `npx vp check`.

## Review Focus

1. **A reviewer of one language must never see or act on another's rows through the Bridge,** including through hand-crafted POST ids. (Task 3 "refuses and counts ids the reviewer can't review"; Task 4 "shows only the reviewer's languages".)
2. **A single approve in focus mode against a string that changed must say so, not "approved".** (Task 3 "reports a conflict on a single approve instead of success".)
3. **In production (not editable), every action button is disabled, and a crafted POST gets a 403.** (Task 3 "refuses writes where review is read-only"; Task 5 "disables actions when not editable".)
4. **"Approve everything clean" must show exact counts before it runs, and never approve errors, or warnings unless ticked.** (Task 4 "previews the approve-all counts"; Task 5 "confirms approve-all with the preview counts".)
5. **Search text with `%`, `_` or `<script>` must be shown as text, never as markup, and must match literally.** (Task 7 "renders search text safely".)

---

## File Structure

**Undaunted, new:**
- `app/Modules/Bridge/Sections/TranslationsSection.php`: the Central Core tile.
- `app/Modules/Bridge/Support/Translations/TranslationPages.php`: the shared page props (shell, viewer, copy) and `respond()`, which runs an action and handles audit, toast and exceptions.
- `app/Modules/Bridge/Support/Translations/ReviewFilters.php`: reads the queue and keys filters from a request.
- `app/Modules/Bridge/Http/Controllers/Translations/`:
  - `OverviewController.php`
  - `ReviewController.php`
  - `KeysController.php`
  - `ApproveController.php`
  - `ApproveMatchingController.php`
  - `RejectController.php`
  - `EditController.php`
  - `WriteController.php`
  - `RedraftController.php`
  - `EstimateController.php`
  - `OperationController.php`
- `app/Modules/Bridge/Lang/en/translations.php`: the copy.
- `resources/js/modules/bridge/pages/Translations/`:
  - `Overview.tsx`, `Review.tsx`, `Keys.tsx`
  - `types.ts`, `fixtures.ts`
  - `useRememberedView.ts`
  - `components/`: `TabBar`, `ViewSwitch`, `HealthStrip`, `LanguageCards`, `LanguageTable`, `QueueTable`, `FocusView`, `KeyMatrix`, `FileEditor`, `SearchResults`, `KeyHistory`
  - a `*.test.tsx` beside each page
- `tests/Feature/Modules/Bridge/Translations/`:
  - `AccessTest.php`
  - `OverviewTest.php`
  - `ActionsTest.php`
  - `ReviewPageTest.php`
  - `KeysPageTest.php`
  - `EndToEndTest.php`

**Undaunted, modified:**
- `app/Modules/Bridge/Providers/BridgeServiceProvider.php`: tag the section, and define the gate.
- `app/Modules/Bridge/Routes/web.php`: the translations routes.
- `tests/Pest.php`: the `translationsWorld()` fixture helper.

---

### Task 1: Section, access gate, routes and the Overview's server side

**Files:**
- Create:
  - `app/Modules/Bridge/Sections/TranslationsSection.php`
  - `app/Modules/Bridge/Support/Translations/TranslationPages.php`
  - `app/Modules/Bridge/Http/Controllers/Translations/OverviewController.php`
  - `app/Modules/Bridge/Lang/en/translations.php`
- Modify: `app/Modules/Bridge/Providers/BridgeServiceProvider.php`, `app/Modules/Bridge/Routes/web.php`, `tests/Pest.php`
- Test: `tests/Feature/Modules/Bridge/Translations/AccessTest.php`, `tests/Feature/Modules/Bridge/Translations/OverviewTest.php`

**Interfaces:**
- Consumes: Prosetta's `Review\Viewer::for(?Authenticatable)`, with `->locales()`, `->manages`, `->toArray()` and the static `editable()`; `Queries\Coverage::for(Viewer): CoverageReport`, with `->toArray()`.
- Produces:
  - the gate `translations.access`;
  - route names:
    - `bridge.translations.index`
    - `.review`
    - `.keys`
    - `.approve`
    - `.approve-matching`
    - `.reject`
    - `.edit`
    - `.write`
    - `.redraft`
    - `.estimate`
    - `.operation`
  - `TranslationPages::props(User $user, string $tab): array`, with keys `navigation`, `section`, `lens`, `viewer`, `tab` and `copy`;
  - `TranslationPages::respond(Closure $action, string $auditAction, string $success): RedirectResponse`;
  - `translationsWorld(): string` in `tests/Pest.php`, which builds a temporary lang tree with `en` and `es` files, points Prosetta at it and syncs.

- [ ] **Step 1: The fixture helper**

Append to `tests/Pest.php`:

```php
/**
 * A small, isolated lang tree for the Bridge Translations tests: English and
 * Spanish root files only (Undaunted's real modules are left out through
 * prosetta.namespaces.include), synced into Prosetta's tables. Returns the
 * temporary directory.
 */
function translationsWorld(): string {
    config(['prosetta.namespaces.include' => [], 'prosetta.resilience.cache_store' => 'array', 'cache.default' => 'array']);
    $dir = sys_get_temp_dir().'/bridge-translations-'.bin2hex(random_bytes(6));
    (new Illuminate\Filesystem\Filesystem)->makeDirectory($dir.'/lang/en', recursive: true);
    (new Illuminate\Filesystem\Filesystem)->makeDirectory($dir.'/lang/es', recursive: true);
    app()->useLangPath($dir.'/lang');
    file_put_contents($dir.'/lang/en/demo.php', "<?php\n\nreturn [\n    'greeting' => 'Hello, :name.',\n    'saved' => 'Saved',\n    'color' => 'Pick a color',\n];\n");
    file_put_contents($dir.'/lang/es/demo.php', "<?php\n\nreturn [\n    'greeting' => 'Hola, :name.',\n    'saved' => 'Guardado',\n];\n");
    app(LonelyLights\Prosetta\Sync\Syncer::class)->sync();

    return $dir;
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Modules/Bridge/Translations/AccessTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Access\PermissionName;
use App\Models\User;
use Database\Seeders\Access\AccessSeeder;
use Database\Seeders\LocalesSeeder;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(AccessSeeder::class);
    translationsWorld();
});

function translationsCrew(array $permissions): User {
    $user = User::factory()->create();
    $user->givePermissionTo([PermissionName::AccessBridge, ...$permissions]);

    return $user;
}

it('turns away a crew member with no translation ability', function () {
    $this->actingAs(translationsCrew([]))->get('/bridge/translations')->assertForbidden();
});

it('lets a reviewer of one language in, and lists the section in Central Core', function () {
    $this->actingAs(translationsCrew(['translations.review.es']))->get('/bridge/translations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('bridge::Translations/Overview')
            ->where('tab', 'overview')
            ->where('viewer.reviews', ['es'])
            ->where('viewer.manages', false));
});

it('lets a manager in, with every target language', function () {
    $this->actingAs(translationsCrew(['translations.manage']))->get('/bridge/translations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('viewer.manages', true)->where('viewer.translates', fn ($codes) => in_array('es', (array) $codes, true)));
});
```

`tests/Feature/Modules/Bridge/Translations/OverviewTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Access\PermissionName;
use App\Models\User;
use Database\Seeders\Access\AccessSeeder;
use Database\Seeders\LocalesSeeder;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(AccessSeeder::class);
    translationsWorld();
});

it('sends coverage for the viewer\'s languages only, with health and the editable flag', function () {
    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo([PermissionName::AccessBridge, 'translations.review.es']);

    $this->actingAs($reviewer)->get('/bridge/translations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('coverage.languages', 1)
            ->where('coverage.languages.0.code', 'es')
            ->where('coverage.languages.0.approved', 2)
            ->where('coverage.languages.0.missing', 1)
            ->has('coverage.problems')
            ->where('viewer.editable', false)
            ->has('copy.overview.title'));
});
```

- [ ] **Step 3: Run the tests and watch them fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations`
Expected: FAIL, with a 404 on `/bridge/translations`.

- [ ] **Step 4: Implement**

`app/Modules/Bridge/Sections/TranslationsSection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Bridge\Sections;

use App\Contracts\Bridge\BridgeGroup;
use App\Contracts\Bridge\BridgeSection;
use App\Contracts\Bridge\SectionStatus;

/** Central Core: reviewing and browsing translations, over Prosetta's review core. */
final readonly class TranslationsSection implements BridgeSection {
    public function group(): BridgeGroup {
        return BridgeGroup::CentralCore;
    }

    public function slug(): string {
        return 'translations';
    }

    public function title(): string {
        return __('bridge::translations.title');
    }

    public function description(): string {
        return __('bridge::translations.description');
    }

    public function icon(): string {
        return 'book-open-check';
    }

    public function permission(): string {
        return 'translations.access';
    }

    public function route(): string {
        return 'bridge.translations.index';
    }

    /** @return array<string, string> */
    public function routeParameters(): array {
        return [];
    }

    public function status(): SectionStatus {
        return SectionStatus::Live;
    }

    public function count(): ?int {
        return null;
    }

    public function order(): int {
        return 3;
    }
}
```

In `BridgeServiceProvider::register()`, next to `LocalesSection`, add:

```php
        $this->app->singleton(TranslationsSection::class);
        $this->app->tag([TranslationsSection::class], 'bridge.sections');
```

In `boot()`, define the gate:

```php
        # Translations Is Open to Anyone Who May Translate a Language or Manage; Founders Pass by the SuperAdmin Override
        Gate::define('translations.access', fn (User $user): bool => ($viewer = Viewer::for($user))->locales() !== [] || $viewer->manages);
```

Add these imports: `App\Models\User`, `Illuminate\Support\Facades\Gate`, `LonelyLights\Prosetta\Review\Viewer` and `App\Modules\Bridge\Sections\TranslationsSection`.

`app/Modules/Bridge/Support/Translations/TranslationPages.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Bridge\Support\Translations;

use App\Contracts\Bridge\Auditor;
use App\Contracts\Bridge\BridgeShell;
use App\Models\User;
use App\Support\Toast;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Exceptions\ReviewConflict;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\Review\Viewer;

/**
 * What every Translations page shares: the Bridge shell, the viewer's
 * abilities and the copy. Also one way to run an action: audit it, toast it,
 * and turn Prosetta's refusals into answers a person understands.
 */
final readonly class TranslationPages {
    public function __construct(private BridgeShell $shell, private Auditor $auditor) {}

    /** @return array<string, mixed> */
    public function props(User $user, string $tab): array {
        $shell = $this->shell->props($user, 'translations');

        return [
            ...$shell,
            'tab' => $tab,
            'viewer' => Viewer::for($user)->toArray(),
            'copy' => [...$shell['copy'], ...(array) trans('bridge::translations')],
        ];
    }

    /**
     * Runs $action (which returns what to record, e.g. a batch report's
     * toArray()), records one audit entry, and toasts $success, or explains
     * why nothing happened.
     *
     * @param Closure(): array<string, mixed> $action
     */
    public function respond(Closure $action, string $auditAction, string $success): RedirectResponse {
        try {
            $result = $action();
        } catch (ReviewLocked $e) {
            abort(403, $e->getMessage());
        } catch (ReviewConflict) {
            Toast::error((string) __('bridge::translations.flash.conflict'));

            return back();
        } catch (ProsettaException $e) {
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }

        $this->auditor->record($auditAction, null, [], [], $result);
        Toast::success($success);

        return back();
    }
}
```

`app/Modules/Bridge/Http/Controllers/Translations/OverviewController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Bridge\Http\Controllers\Translations;

use App\Models\User;
use App\Modules\Bridge\Support\Translations\TranslationPages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LonelyLights\Prosetta\Queries\Coverage;
use LonelyLights\Prosetta\Review\Viewer;

/** /bridge/translations: how far each of the viewer's languages is, and how the automation is doing. */
final readonly class OverviewController {
    public function __construct(private TranslationPages $pages, private Coverage $coverage) {}

    public function __invoke(Request $request): Response {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('bridge::Translations/Overview', [
            ...$this->pages->props($user, 'overview'),
            'coverage' => $this->coverage->for(Viewer::for($user))->toArray(),
        ]);
    }
}
```

In `app/Modules/Bridge/Routes/web.php`, add inside the `can:access-bridge` group, after the Locales block:

```php
    # Central Core: Reviewing Translations, for Anyone Who May Translate a Language or Manage.
    # Every Write Is Checked Again, per Language, by Prosetta's Review Core.
    Route::middleware('can:translations.access')->prefix('translations')->name('translations.')->group(function (): void {
        Route::get('/', TranslationsOverviewController::class)->name('index');
        Route::get('/review', TranslationsReviewController::class)->name('review');
        Route::get('/keys', TranslationsKeysController::class)->name('keys');
        Route::post('/review/approve', TranslationsApproveController::class)->name('approve');
        Route::post('/review/approve-matching', TranslationsApproveMatchingController::class)->name('approve-matching');
        Route::post('/review/reject', TranslationsRejectController::class)->name('reject');
        Route::post('/review/{translation}/edit', TranslationsEditController::class)->name('edit')->whereNumber('translation');
        Route::post('/keys/write', TranslationsWriteController::class)->name('write');
        Route::post('/review/redraft', TranslationsRedraftController::class)->name('redraft');
        Route::get('/review/estimate', TranslationsEstimateController::class)->name('estimate');
        Route::post('/operations/{operation}', TranslationsOperationController::class)->name('operation')->whereIn('operation', ['sync', 'cycle', 'export']);
    });
```

In this task, register only the `index` route and its import:

```php
use App\Modules\Bridge\Http\Controllers\Translations\OverviewController as TranslationsOverviewController;
```

Tasks 3, 4 and 6 add the other routes and their imports as each controller arrives.

`app/Modules/Bridge/Lang/en/translations.php`. Other tasks add keys to this array; keep one file:

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Translations',
    'description' => 'Review drafts, browse every key, and see how each language is doing.',
    'tabs' => ['overview' => 'Overview', 'review' => 'Review', 'keys' => 'Keys'],
    'views' => ['label' => 'View', 'cards' => 'Cards', 'table' => 'Table', 'focus' => 'Focus', 'matrix' => 'Matrix', 'file' => 'File', 'search' => 'Search'],
    'readOnly' => 'Translations are read-only here: approvals made in this environment could never reach the lang files.',
    'overview' => [
        'title' => 'Translation overview',
        'mode' => ['ai' => 'AI', 'derived' => 'Derived, 0 tokens'],
        'states' => ['approved' => 'Approved', 'draft' => 'Draft', 'flagged' => 'Flagged', 'pending' => 'Hand edits', 'stale' => 'Stale', 'missing' => 'Missing', 'held' => 'Held'],
        'keys' => ':count keys',
        'tokens' => ':count tokens this month',
        'allCurrent' => 'All current',
        'health' => ['lastCycle' => 'Last cycle :when', 'noCycle' => 'No cycle has run yet', 'healthy' => 'Everything is healthy', 'budget' => ':period budget :percent% used'],
        'lastReport' => 'Last cycle: :drafted drafted, :updated updated, :confirmed confirmed, :approved approved, :flagged flagged.',
        'operations' => ['sync' => 'Sync', 'cycle' => 'Run cycle now', 'export' => 'Export'],
    ],
    'flash' => [
        'conflict' => 'This changed since you opened it. The fresh version is shown.',
        'synced' => 'Synced.',
        'cycleQueued' => 'A cycle is queued.',
        'exported' => 'Exported.',
    ],
];
```

**A placeholder page.** `config/inertia.php` has `testing.ensure_pages_exist` set to `true`, so `assertInertia` fails for a page file that doesn't exist. Create `resources/js/modules/bridge/pages/Translations/Overview.tsx` containing only:

```tsx
/** Placeholder until Task 2 builds the overview page. */
export default function Overview() {
    return null;
}
```

- [ ] **Step 5: Run the tests and watch them pass, then run the suite**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations`
Expected: PASS, 4 tests.

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact --parallel`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Bridge tests/Pest.php tests/Feature/Modules/Bridge/Translations
git commit -m "feat(bridge): a Translations section with an access gate and the overview's data"
```

---

### Task 2: The Overview page (cards or table) and the shared page pieces

**Files:**
- Create in `resources/js/modules/bridge/pages/Translations/`:
  - `Overview.tsx`, `types.ts`, `fixtures.ts`, `useRememberedView.ts`
  - `components/TabBar.tsx`, `components/ViewSwitch.tsx`, `components/HealthStrip.tsx`, `components/LanguageCards.tsx`, `components/LanguageTable.tsx`
- Test: `resources/js/modules/bridge/pages/Translations/Overview.test.tsx`

**Interfaces:**
- Consumes: Task 1's props (`navigation`, `section`, `lens`, `tab`, `viewer`, `copy`, `coverage`); Wayfinder's `@/routes/bridge/translations` (`index`, `review`, `keys`, `operation`); the shared `BridgeLayout`, and `GroupTiles`, `Tile`, `BridgeLens`, `ShellCopy` from `../../lib/types`.
- Produces, for Tasks 5 and 7:
  - `useRememberedView<T extends string>(page: string, views: readonly T[], fallback: T): [T, (view: T) => void]`
  - `<TabBar tab copy />`
  - `<ViewSwitch views value onChange copy />`
  - `types.ts`: `Viewer`, `TranslationsCopy`, `TranslationsShell`, `CoverageLanguage` and `OverviewPage`

- [ ] **Step 1: Types and fixtures**

`types.ts`:

```ts
import type { BridgeLens, GroupTiles, ShellCopy, Tile } from '../../lib/types';

export interface Viewer { translates: string[]; reviews: string[]; manages: boolean; editable: boolean }

export type ReviewReason = 'draft' | 'flagged' | 'pending' | 'stale' | 'held';
export type CellStatus = ReviewReason | 'approved' | 'missing';

export interface TranslationsCopy {
    shell: ShellCopy;
    title: string;
    description: string;
    tabs: { overview: string; review: string; keys: string };
    views: Record<'label' | 'cards' | 'table' | 'focus' | 'matrix' | 'file' | 'search', string>;
    readOnly: string;
    overview: {
        title: string;
        mode: { ai: string; derived: string };
        states: Record<CellStatus, string>;
        keys: string;
        tokens: string;
        allCurrent: string;
        health: { lastCycle: string; noCycle: string; healthy: string; budget: string };
        lastReport: string;
        operations: { sync: string; cycle: string; export: string };
    };
    [section: string]: unknown;
}

export interface TranslationsShell {
    lens?: BridgeLens;
    navigation: GroupTiles[];
    section: Tile;
    tab: 'overview' | 'review' | 'keys';
    viewer: Viewer;
    copy: TranslationsCopy;
}

export interface CoverageLanguage {
    code: string; name: string; nativeName: string; mode: 'ai' | 'derived'; keys: number;
    approved: number; draft: number; flagged: number; pending: number; stale: number; missing: number; held: number;
    tokensThisMonth: number;
}

export interface OverviewPage extends TranslationsShell {
    coverage: {
        languages: CoverageLanguage[];
        lastCycleAt: number | null;
        lastReport: { drafted: number; updated: number; confirmed: number; approved: number; flagged: string[]; files: string[]; at: number } | null;
        circuits: { name: string; state: string; reason: string | null; until: number | null }[];
        budget: Record<string, { used: number; limit: number | null }>;
        problems: string[];
        editable: boolean;
    };
}
```

`fixtures.ts`: export `shell(overrides?: Partial<TranslationsShell>): TranslationsShell`. Build it the way `Audit/Index.test.tsx` builds `navigation`, `section` (slug `translations`, group `central-core`) and `copy.shell`. Its `viewer` is `{ translates: ['es', 'ar'], reviews: ['es'], manages: true, editable: true }`. Its `copy` must contain every key from `translations.php` in Task 1, with the same English values. Also export `coverageFixture: OverviewPage['coverage']`, with:
- Spanish: AI, 2083 keys, 2074 approved, 3 flagged, 6 stale;
- British English: derived, 2083 keys, 2083 approved;
- `lastCycleAt` set to a fixed timestamp;
- `problems: []`.

- [ ] **Step 2: Write the failing test**

`Overview.test.tsx`:

```tsx
// @vitest-environment happy-dom
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import { TooltipProvider } from '@/components/primitives/tooltip';
import { coverageFixture, shell } from './fixtures';
import Overview from './Overview';

const renderOverview = (overrides = {}) => render(
    <TooltipProvider><Overview {...shell({ tab: 'overview' })} coverage={coverageFixture} {...overrides} /></TooltipProvider>,
);

describe('Translations overview', () => {
    beforeEach(() => localStorage.clear());

    it('shows a card per language with its mode, and links its counts into the review queue', () => {
        renderOverview();

        const spanish = screen.getByRole('article', { name: /Spanish/ });
        expect(within(spanish).getByText('AI')).toBeTruthy();
        expect(within(spanish).getByRole('link', { name: /3 Flagged/ }).getAttribute('href')).toContain('locale=es&reason=flagged');
        expect(screen.getByText('Derived, 0 tokens')).toBeTruthy();
    });

    it('switches to the table view and remembers the choice', () => {
        const { unmount } = renderOverview();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(screen.getByRole('table')).toBeTruthy();
        unmount();

        renderOverview();
        expect(screen.getByRole('table')).toBeTruthy();
    });

    it('offers operations to a manager, disabled with a note when not editable', () => {
        renderOverview({ viewer: { translates: ['es'], reviews: ['es'], manages: true, editable: false } });

        expect((screen.getByRole('button', { name: 'Run cycle now' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText(/read-only here/)).toBeTruthy();
    });

    it('hides operations from someone who can\'t manage', () => {
        renderOverview({ viewer: { translates: ['es'], reviews: ['es'], manages: false, editable: true } });

        expect(screen.queryByRole('button', { name: 'Sync' })).toBeNull();
    });
});
```

- [ ] **Step 3: Run the test and watch it fail**

Run: `php artisan wayfinder:generate && npx vitest run resources/js/modules/bridge/pages/Translations/Overview.test.tsx`
Expected: FAIL, because `./Overview` can't be resolved.

- [ ] **Step 4: Implement**

`useRememberedView.ts`:

```ts
import { useState } from 'react';

/** One page's chosen view, remembered per browser; any storage failure falls back to the default. */
export function useRememberedView<T extends string>(page: string, views: readonly T[], fallback: T): [T, (view: T) => void] {
    const key = `bridge.translations.${page}.view`;
    const [view, setView] = useState<T>(() => {
        try {
            const stored = localStorage.getItem(key);

            return stored !== null && (views as readonly string[]).includes(stored) ? (stored as T) : fallback;
        } catch {
            return fallback;
        }
    });

    return [view, (next: T) => {
        setView(next);

        try {
            localStorage.setItem(key, next);
        } catch {
            // Private windows and blocked storage just forget the choice.
        }
    }];
}
```

`components/ViewSwitch.tsx`: a `role="radiogroup"` labelled `copy.views.label`, with one `role="radio"` button per view, `aria-checked` on the current one, and `onClick={() => onChange(view)}`. The label for each view is `copy.views[view]`.

`components/TabBar.tsx`: a `<nav aria-label={copy.title}>` holding three Inertia `<Link>`s to `index().url`, `review().url` and `keys().url`, labelled from `copy.tabs`, with `aria-current="page"` on the current tab.

`components/HealthStrip.tsx`:
- **Pills:** "Last cycle :when" is filled with a relative time from `lastCycleAt` (`Intl.RelativeTimeFormat`). When `lastCycleAt` is null, show `noCycle` instead.
- **Problems:** one pill per `problems` line, with the bracketed code stripped for display.
- **Budget:** one pill per budget period with a limit, showing the percentage used.
- **All clear:** `healthy` when there are no problems.

`components/LanguageCards.tsx`: one `<article aria-label={name}>` per language, containing:
- the name, and the mode label from `copy.overview.mode`;
- a stacked coverage bar (approved, draft, flagged, stale, missing);
- a count link for each needs-person state with a count above 0: flagged, pending, stale, held, draft. Each link text is "{n} {state label}", and its href is `review({ query: { locale: code, reason: state } }).url`;
- tokens this month;
- `allCurrent` when every needs-person count is 0.

`components/LanguageTable.tsx`: a `<table>` with columns for language, mode, coverage bar and the state counts, each count linking the same way as the cards. Sorting is by clicking a header, in local state.

`Overview.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { home } from '@/routes/bridge';
import { operation } from '@/routes/bridge/translations';
import { BridgeLayout } from '../../components/BridgeLayout/BridgeLayout';
import { HealthStrip } from './components/HealthStrip';
import { LanguageCards } from './components/LanguageCards';
import { LanguageTable } from './components/LanguageTable';
import { TabBar } from './components/TabBar';
import { ViewSwitch } from './components/ViewSwitch';
import type { OverviewPage } from './types';
import { useRememberedView } from './useRememberedView';

const VIEWS = ['cards', 'table'] as const;

/** /bridge/translations: coverage per language, the automation's health, and (for managers) sync, cycle and export. */
export default function Overview({ navigation, section, lens, viewer, copy, coverage }: OverviewPage) {
    const [view, setView] = useRememberedView('overview', VIEWS, 'cards');

    return (
        <BridgeLayout lens={lens} title={copy.title} copy={copy.shell} navigation={navigation} group={section.group} section={section.slug}
            breadcrumbs={[{ label: copy.shell.title, href: home().url }, { label: section.groupLabel }]}>
            <TabBar tab="overview" copy={copy} />
            {!viewer.editable && <p role="note" className="mb-4 text-sm">{copy.readOnly}</p>}
            <HealthStrip coverage={coverage} copy={copy} />
            <ViewSwitch views={VIEWS} value={view} onChange={setView} copy={copy} />
            {view === 'cards' ? <LanguageCards languages={coverage.languages} copy={copy} /> : <LanguageTable languages={coverage.languages} copy={copy} />}
            {viewer.manages && (
                <div className="mt-6 flex gap-2">
                    {(['sync', 'cycle', 'export'] as const).map((name) => (
                        <button key={name} type="button" disabled={!viewer.editable} className="btn"
                            onClick={() => router.post(operation(name).url, {}, { preserveScroll: true })}>
                            {copy.overview.operations[name]}
                        </button>
                    ))}
                </div>
            )}
        </BridgeLayout>
    );
}
```

For button and bar classes, use the same primitives the Locales page uses (`resources/js/modules/bridge/pages/Locales/Index.tsx`). Keep the styling plain: Task 8 is the visual pass.

- [ ] **Step 5: Run the test and watch it pass, then the checks**

Run: `npx vitest run resources/js/modules/bridge/pages/Translations/Overview.test.tsx`
Expected: PASS, 4 tests.

Run: `npm run -s types:check && npx vp check`
Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add resources/js/modules/bridge/pages/Translations
git commit -m "feat(bridge): the translations overview, as cards or a table, remembered per person"
```

---

### Task 3: The review actions

**Files:**
- Create in `app/Modules/Bridge/Http/Controllers/Translations/`:
  - `ApproveController.php`, `ApproveMatchingController.php`, `RejectController.php`, `EditController.php`
  - `RedraftController.php`, `EstimateController.php`, `OperationController.php`
- Create: `app/Modules/Bridge/Support/Translations/ReviewFilters.php`
- Modify: `app/Modules/Bridge/Routes/web.php` (those seven routes and their imports), `app/Modules/Bridge/Lang/en/translations.php` (the `flash` keys below)
- Test: `tests/Feature/Modules/Bridge/Translations/ActionsTest.php`

**Interfaces:**
- Consumes:
  - `TranslationPages::respond`;
  - `ReviewDesk::approveMany(Viewer, array<int,string>)`, `approveMatching(Viewer, array, bool)`, `rejectMany(Viewer, array<int,string>, string)`, `estimateRedraft(array<string,list<string>>)`, `redraft(Viewer, array)` and `runCycle(Viewer)`, which return a `BatchReport` or nothing;
  - `BatchReport::toArray()`, with `approved`, `rejected`, `skippedWarnings`, `skippedErrors`, `conflicts`, `forbidden` and `skipped`;
  - `ReviewService::edit(int, string, ?Authenticatable, ?string $notes, bool $approve, ?string $expected)`;
  - `ProsettaManager::sync(?array, bool, ?Authenticatable)` and `export(array, array, ?bool, bool, ?Authenticatable)`.
- Produces:
  - `ReviewFilters::from(Request): array{locale: ?string, reason: ?string, namespace: ?string, group: ?string, search: ?string}`;
  - these POST bodies:

    | Route | Body |
    |---|---|
    | `approve` | `items` (object: translation id → fingerprint) |
    | `approve-matching` | the filters, plus `includeWarnings` (bool) |
    | `reject` | `items` and `note` (required, max 1000) |
    | `edit` | `value` (required, max 5000), `approve` (bool) and `fingerprint` (string) |
    | `redraft` | `refs` (object: locale → list of key refs) |
    | `estimate` (GET) | `refs`, the same shape, in the query; it returns JSON of the `Estimator` shape |

  - `operation/{sync|cycle|export}`, with no body.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Modules/Bridge/Translations/ActionsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Access\PermissionName;
use App\Models\User;
use App\Modules\Bridge\Models\BridgeAudit;
use Database\Seeders\Access\AccessSeeder;
use Database\Seeders\LocalesSeeder;
use Illuminate\Support\Facades\Bus;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(AccessSeeder::class);
    translationsWorld();
    config(['prosetta.review.editable' => true]);
    $this->reviewer = User::factory()->create();
    $this->reviewer->givePermissionTo([PermissionName::AccessBridge, 'translations.review.es']);
});

function actionDraft(string $ref, string $locale, string $value, ?array $issues = null): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->updateOrCreate(['key_id' => $key->id, 'locale' => $locale], [
        'value' => $value, 'source_hash' => $key->source_hash, 'status' => TranslationStatus::Draft, 'origin' => 'ai', 'issues' => $issues,
    ]);
}

it('approves selected items, audits once, and toasts the count', function () {
    $draft = actionDraft('demo.color', 'es', 'Elige un color');

    $this->actingAs($this->reviewer)
        ->post('/bridge/translations/review/approve', ['items' => [$draft->id => ReviewService::fingerprint($draft)]])
        ->assertRedirect();

    expect($draft->refresh()->status)->toBe(TranslationStatus::Approved)
        ->and(BridgeAudit::query()->where('action', 'translations.approve')->count())->toBe(1);
});

it('reports a conflict on a single approve instead of success', function () {
    $draft = actionDraft('demo.color', 'es', 'Elige un color');

    $this->actingAs($this->reviewer)
        ->post('/bridge/translations/review/approve', ['items' => [$draft->id => 'stale-fingerprint']])
        ->assertRedirect()
        ->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'error' && str_contains($toast['message'], 'changed since you opened it'));

    expect($draft->refresh()->status)->toBe(TranslationStatus::Draft);
});

it('refuses and counts ids the reviewer can\'t review', function () {
    $arabic = actionDraft('demo.color', 'ar', 'اختر لونًا');

    $this->actingAs($this->reviewer)
        ->post('/bridge/translations/review/approve', ['items' => [$arabic->id => ReviewService::fingerprint($arabic)]])
        ->assertRedirect();

    expect($arabic->refresh()->status)->toBe(TranslationStatus::Draft);
});

it('approves everything clean matching a filter, leaving errors and warnings unless asked', function () {
    actionDraft('demo.color', 'es', 'Elige un color');
    $warned = actionDraft('demo.greeting', 'es', 'Hola, :name.', [['code' => 'glossary_missing', 'severity' => 'warning', 'message' => 'x']]);

    $this->actingAs($this->reviewer)->post('/bridge/translations/review/approve-matching', ['locale' => 'es'])->assertRedirect();
    expect($warned->refresh()->status)->toBe(TranslationStatus::Draft);

    $this->actingAs($this->reviewer)->post('/bridge/translations/review/approve-matching', ['locale' => 'es', 'includeWarnings' => true])->assertRedirect();
    expect($warned->refresh()->status)->toBe(TranslationStatus::Approved);
});

it('rejects with a required note', function () {
    $draft = actionDraft('demo.color', 'es', 'Escoge color');

    $this->actingAs($this->reviewer)->post('/bridge/translations/review/reject', ['items' => [$draft->id => ReviewService::fingerprint($draft)]])
        ->assertSessionHasErrors('note');

    $this->actingAs($this->reviewer)->post('/bridge/translations/review/reject', ['items' => [$draft->id => ReviewService::fingerprint($draft)], 'note' => 'Too curt.'])
        ->assertRedirect();

    expect($draft->refresh()->status)->toBe(TranslationStatus::Rejected);
});

it('edits and approves, refusing a value that breaks a placeholder', function () {
    $draft = actionDraft('demo.greeting', 'es', 'Hola, :name.');

    $this->actingAs($this->reviewer)->post("/bridge/translations/review/{$draft->id}/edit", ['value' => 'Hola.', 'approve' => true, 'fingerprint' => ReviewService::fingerprint($draft)])
        ->assertSessionHasErrors('value');

    $this->actingAs($this->reviewer)->post("/bridge/translations/review/{$draft->id}/edit", ['value' => '¡Hola, :name!', 'approve' => true, 'fingerprint' => ReviewService::fingerprint($draft->refresh())])
        ->assertRedirect();

    expect($draft->refresh()->approved_value)->toBe('¡Hola, :name!');
});

it('estimates and queues a re-draft', function () {
    Bus::fake();
    app()->instance(LonelyLights\Prosetta\Contracts\TranslationDriver::class, new LonelyLights\Prosetta\Testing\ScriptedDriver);

    $this->actingAs($this->reviewer)->getJson('/bridge/translations/review/estimate?'.http_build_query(['refs' => ['es' => ['demo.color']]]))
        ->assertOk()->assertJsonPath('es.strings', 1);
    $this->actingAs($this->reviewer)->post('/bridge/translations/review/redraft', ['refs' => ['es' => ['demo.color']]])->assertRedirect();

    Bus::assertBatchCount(1);
});

it('runs operations only for managers', function () {
    $this->actingAs($this->reviewer)->post('/bridge/translations/operations/sync')->assertForbidden();

    $manager = User::factory()->create();
    $manager->givePermissionTo([PermissionName::AccessBridge, 'translations.manage']);

    $this->actingAs($manager)->post('/bridge/translations/operations/sync')->assertRedirect();
});

it('refuses writes where review is read-only', function () {
    config(['prosetta.review.editable' => false]);
    $draft = actionDraft('demo.color', 'es', 'Elige un color');

    $this->actingAs($this->reviewer)->post('/bridge/translations/review/approve', ['items' => [$draft->id => ReviewService::fingerprint($draft)]])
        ->assertForbidden();
});
```

`App\Support\Toast` flashes through `Inertia::flash('toast', ['id' => …, 'type' => …, 'message' => …])`, so a later toast replaces an earlier one in the same request. Check where Inertia 2 keeps flash data in the session (for example with `dd(session()->all())` in a scratch test), and adjust the conflict test's `assertSessionHas` key to match. The assertion is on `type` and `message`.

- [ ] **Step 2: Run the tests and watch them fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations/ActionsTest.php`
Expected: FAIL, with 404s.

- [ ] **Step 3: Implement**

`ReviewFilters.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Bridge\Support\Translations;

use Illuminate\Http\Request;

/** The review and keys filters, read from the URL or a form; blanks become null. */
final readonly class ReviewFilters {
    /** @return array{locale: ?string, reason: ?string, namespace: ?string, group: ?string, search: ?string} */
    public static function from(Request $request): array {
        $read = fn (string $name): ?string => is_string($value = $request->input($name)) && trim($value) !== '' ? trim($value) : null;

        return ['locale' => $read('locale'), 'reason' => $read('reason'), 'namespace' => $read('namespace'), 'group' => $read('group'), 'search' => $read('search')];
    }
}
```

`ApproveController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Bridge\Http\Controllers\Translations;

use App\Modules\Bridge\Support\Translations\TranslationPages;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LonelyLights\Prosetta\Review\ReviewDesk;
use LonelyLights\Prosetta\Review\Viewer;

/** Approves the selected items (or the one in focus), each checked against the fingerprint the page showed. */
final readonly class ApproveController {
    public function __construct(private TranslationPages $pages, private ReviewDesk $desk) {}

    public function __invoke(Request $request): RedirectResponse {
        $items = $request->validate(['items' => ['required', 'array', 'max:500'], 'items.*' => ['required', 'string']])['items'];
        $conflicts = 0;

        $response = $this->pages->respond(function () use ($request, $items, &$conflicts): array {
            $report = $this->desk->approveMany(Viewer::for($request->user()), array_map('strval', $items));
            $conflicts = $report->conflicts;

            return $report->toArray();
        }, 'translations.approve', (string) __('bridge::translations.flash.approved', ['count' => count($items)]));

        # Prosetta Records a Changed Item as a Conflict Rather Than Throwing: Say So Instead of "Approved"
        if ($conflicts > 0) {
            Toast::error((string) __('bridge::translations.flash.conflict'));
        }

        return $response;
    }
}
```

Toast replacement rule: if `Toast::error` after `Toast::success` doesn't replace the flashed toast, restructure so that only one toast is flashed. Check Toast's semantics.

The rest follow the same shape:
- **`ApproveMatchingController`:** `$this->desk->approveMatching(Viewer::for($user), ReviewFilters::from($request), $request->boolean('includeWarnings'))`, with the audit action `translations.approve-matching`. It toasts `flash.approvedMatching`, filled with `approved`, `skippedWarnings` and `skippedErrors` from the report.
- **`RejectController`:** validate `items` and `note` (`required|string|max:1000`), then call `rejectMany`, with the audit action `translations.reject` and `flash.rejected`.
- **`EditController`:** validate `value` (`required|string|max:5000`), `approve` (boolean) and `fingerprint` (`required|string`). Call `app(ReviewService::class)->edit($translation, $value, $user, null, $request->boolean('approve'), $fingerprint)`, with the audit action `translations.edit` and `flash.edited` or `flash.approved`. Here `$translation` is the route's integer.
- **`RedraftController`:** validate `refs` (`required|array`, keys are locales, values are arrays of strings), then call `redraft`, with the audit action `translations.redraft` and `flash.redraftQueued`.
- **`EstimateController`:** return `response()->json(app(ReviewDesk::class)->estimateRedraft($refs))`, with no audit. Keep only the locales in `$viewer->locales()`.
- **`OperationController`:** a `match` on `$operation`:
  - `sync` calls `app(ProsettaManager::class)->sync(by: $user)`;
  - `cycle` calls `$desk->runCycle($viewer)`;
  - `export` calls `app(ProsettaManager::class)->export(by: $user)`.

  Each uses the audit action `translations.{operation}` and its own flash key. Before the `match`, abort with a 403 unless the viewer manages.

Add these keys to the `flash` array in `translations.php`:

```php
        'approved' => 'Approved :count.',
        'approvedMatching' => 'Approved :approved. Left for you: :warnings with warnings, :errors with errors.',
        'rejected' => 'Rejected :count.',
        'edited' => 'Saved for review.',
        'redraftQueued' => 'Asked the AI again; the new drafts will appear here.',
```

Register the seven routes from Task 1's block, with their imports.

- [ ] **Step 4: Run the tests and watch them pass, then the suite**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations`
Expected: PASS, 13 tests.

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact --parallel`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Bridge tests/Feature/Modules/Bridge/Translations
git commit -m "feat(bridge): review actions (approve, approve everything clean, reject, edit, re-draft) and operations"
```

---

### Task 4: The Review page's server side

**Files:**
- Create: `app/Modules/Bridge/Http/Controllers/Translations/ReviewController.php`
- Modify: `app/Modules/Bridge/Routes/web.php` (the `review` route), `app/Modules/Bridge/Lang/en/translations.php` (the `review` copy)
- Test: `tests/Feature/Modules/Bridge/Translations/ReviewPageTest.php`

**Interfaces:**
- Consumes: `ReviewQueue::for(Viewer, array $filters, int $page, int $perPage): LengthAwarePaginator<QueueItem>` and `ReviewQueue::all(Viewer, array)`; `QueueItem::toArray()` (`translationId`, `keyId`, `keyRef`, `namespace`, `group`, `locale`, `reason`, `source`, `previousSource`, `diff`, `candidate`, `approved`, `issues`, `blocking`, `warnings`, `origin`, `updatedAt` and `fingerprint`); `ReviewFilters::from`.
- Produces the props of `bridge::Translations/Review`:
  - `queue`: `{rows: QueueItem[], meta: {total, perPage, currentPage, lastPage}}`;
  - `filters`: the current filters;
  - `options`: `{locales: {code, name}[], reasons: string[]}`, where the locales are the viewer's;
  - `preview`: `{clean: int, warnings: int, errors: int}`. It counts only the items the viewer may review whose reason is `draft`, `flagged` or `pending`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Modules/Bridge/Translations/ReviewPageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Access\PermissionName;
use App\Models\User;
use Database\Seeders\Access\AccessSeeder;
use Database\Seeders\LocalesSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(AccessSeeder::class);
    translationsWorld();
    $this->reviewer = User::factory()->create();
    $this->reviewer->givePermissionTo([PermissionName::AccessBridge, 'translations.review.es']);
});

function reviewPageDraft(string $ref, string $locale, string $value, ?array $issues = null): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->updateOrCreate(['key_id' => $key->id, 'locale' => $locale], [
        'value' => $value, 'source_hash' => $key->source_hash, 'status' => TranslationStatus::Draft, 'origin' => 'ai', 'issues' => $issues,
    ]);
}

it('shows only the reviewer\'s languages', function () {
    reviewPageDraft('demo.color', 'es', 'Elige un color');
    reviewPageDraft('demo.color', 'ar', 'اختر لونًا');

    $this->actingAs($this->reviewer)->get('/bridge/translations/review')
        ->assertInertia(fn (Assert $page) => $page->component('bridge::Translations/Review')
            ->has('queue.rows', 1)
            ->where('queue.rows.0.locale', 'es')
            ->where('options.locales.0.code', 'es'));
});

it('filters by reason from the URL and pages', function () {
    reviewPageDraft('demo.color', 'es', 'Elige un color');

    $this->actingAs($this->reviewer)->get('/bridge/translations/review?locale=es&reason=flagged')
        ->assertInertia(fn (Assert $page) => $page->has('queue.rows', 0)->where('filters.reason', 'flagged')->where('queue.meta.total', 0));
});

it('previews the approve-all counts', function () {
    reviewPageDraft('demo.color', 'es', 'Elige un color');
    reviewPageDraft('demo.greeting', 'es', 'Hola, :name.', [['code' => 'glossary_missing', 'severity' => 'warning', 'message' => 'x']]);
    reviewPageDraft('demo.saved', 'es', 'Guardado :x', [['code' => 'placeholder_extra', 'severity' => 'error', 'message' => 'x']]);

    $this->actingAs($this->reviewer)->get('/bridge/translations/review?locale=es')
        ->assertInertia(fn (Assert $page) => $page->where('preview.clean', 1)->where('preview.warnings', 1)->where('preview.errors', 1));
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations/ReviewPageTest.php`
Expected: FAIL, with a 404.

- [ ] **Step 3: Implement**

`ReviewController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Bridge\Http\Controllers\Translations;

use App\Models\User;
use App\Modules\Bridge\Support\Translations\ReviewFilters;
use App\Modules\Bridge\Support\Translations\TranslationPages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Review\QueueItem;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\Status;
use LonelyLights\Prosetta\Review\Viewer;

/** /bridge/translations/review: what needs a person, in the viewer's languages. */
final readonly class ReviewController {
    private const int PER_PAGE = 50;

    public function __construct(private TranslationPages $pages, private ReviewQueue $queue, private LocaleSource $locales) {}

    public function __invoke(Request $request): Response {
        /** @var User $user */
        $user = $request->user();
        $viewer = Viewer::for($user);
        $filters = ReviewFilters::from($request);
        $page = $this->queue->for($viewer, $filters, max(1, $request->integer('page', 1)), self::PER_PAGE);
        $preview = ['clean' => 0, 'warnings' => 0, 'errors' => 0];

        foreach ($this->queue->all($viewer, $filters) as $item) {
            if ($item->candidate === null || ! in_array($item->reason, ['draft', 'flagged', 'pending'], true) || ! $viewer->canReview($item->locale)) {
                continue;
            }

            $preview[$item->blocking ? 'errors' : ($item->warnings ? 'warnings' : 'clean')]++;
        }

        return Inertia::render('bridge::Translations/Review', [
            ...$this->pages->props($user, 'review'),
            'queue' => [
                'rows' => array_map(fn (QueueItem $item) => $item->toArray(), $page->items()),
                'meta' => ['total' => $page->total(), 'perPage' => $page->perPage(), 'currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage()],
            ],
            'filters' => $filters,
            'options' => [
                'locales' => array_map(fn (string $code) => ['code' => $code, 'name' => $this->locales->find($code)?->englishName ?? $code], $viewer->locales()),
                'reasons' => Status::NEEDS_PERSON,
            ],
            'preview' => $preview,
        ]);
    }
}
```

Create a placeholder page, `resources/js/modules/bridge/pages/Translations/Review.tsx`, containing `export default function Review() { return null; }` with a one-line docblock. Inertia's `ensure_pages_exist` needs it; Task 5 replaces it.

Register the `review` route. Add a `review` block to `translations.php`:

```php
    'review' => [
        'title' => 'Review',
        'filters' => ['locale' => 'Language', 'reason' => 'Reason', 'search' => 'Search', 'all' => 'All'],
        'reasons' => ['draft' => 'Draft', 'flagged' => 'Flagged', 'pending' => 'Hand edit', 'stale' => 'Stale', 'held' => 'Held'],
        'columns' => ['key' => 'Key', 'reason' => 'Reason', 'english' => 'English', 'candidate' => 'Translation', 'age' => 'Age'],
        'actions' => ['approve' => 'Approve', 'edit' => 'Edit', 'reject' => 'Reject', 'redraft' => 'Ask the AI again', 'skip' => 'Skip', 'save' => 'Save', 'saveApprove' => 'Save and approve', 'cancel' => 'Cancel'],
        'batch' => [
            'approveSelected' => 'Approve :count selected',
            'rejectSelected' => 'Reject selected',
            'redraftSelected' => 'Ask the AI again for selected',
            'approveAll' => 'Approve everything clean in this view',
            'confirmAll' => ':clean will be approved. Left for you: :warnings with warnings and :errors with errors.',
            'includeWarnings' => 'I\'ve checked the ones with warnings; approve them too',
            'estimate' => 'About :tokens tokens.',
        ],
        'note' => ['label' => 'Why? (the AI sees this with its next draft)', 'required' => 'A note helps the next draft.'],
        'changed' => 'What changed in the English',
        'focusPosition' => ':current of :total',
        'empty' => 'Nothing needs you in :language.',
        'emptyAll' => 'Nothing needs you right now.',
        'browseKeys' => 'Browse the keys',
    ],
```

- [ ] **Step 4: Run the tests and watch them pass, then the suite**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations`
Expected: PASS, 16 tests.

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact --parallel`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Bridge tests/Feature/Modules/Bridge/Translations
git commit -m "feat(bridge): the review page's data: the viewer's queue, URL filters and approve-all preview counts"
```

---

### Task 5: The Review page (table with batch actions, or focus mode)

**Files:**
- Create in `resources/js/modules/bridge/pages/Translations/`: `Review.tsx`, `components/QueueTable.tsx`, `components/FocusView.tsx`, `components/NoteDialog.tsx`
- Modify: `types.ts` (`ReviewPage`, `QueueItem`), `fixtures.ts` (`queueFixture`, and the `review` copy)
- Test: `resources/js/modules/bridge/pages/Translations/Review.test.tsx`

**Interfaces:**
- Consumes:
  - Task 4's props;
  - Wayfinder `approve`, `approveMatching`, `reject`, `edit`, `redraft`, `estimate` and `review`;
  - Task 2's `useRememberedView`, `TabBar` and `ViewSwitch`.
- Produces: the `bridge::Translations/Review` page.

- [ ] **Step 1: Types and fixtures**

Add to `types.ts`:

```ts
export interface QueueItem {
    translationId: number | null; keyId: number; keyRef: string; namespace: string; group: string; locale: string;
    reason: ReviewReason; source: string; previousSource: string | null; diff: string | null;
    candidate: string | null; approved: string | null; issues: { code: string; severity: string; message: string }[];
    blocking: boolean; warnings: boolean; origin: string | null; updatedAt: string | null; fingerprint: string | null;
}

export interface ReviewPage extends TranslationsShell {
    queue: { rows: QueueItem[]; meta: { total: number; perPage: number; currentPage: number; lastPage: number } };
    filters: { locale: string | null; reason: string | null; namespace: string | null; group: string | null; search: string | null };
    options: { locales: { code: string; name: string }[]; reasons: ReviewReason[] };
    preview: { clean: number; warnings: number; errors: number };
}
```

In `fixtures.ts`, export `queueFixture: QueueItem[]` with three Spanish items:
1. a clean `draft`;
2. a `flagged` item with a `glossary_banned` error;
3. a `stale` update with `previousSource` and `diff: '[-at-] {+into+} it {+soon+}'`.

Also add the `review` copy from Task 4 to `shell().copy`.

- [ ] **Step 2: Write the failing test**

`Review.test.tsx`:

```tsx
// @vitest-environment happy-dom
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/primitives/tooltip';
import { queueFixture, shell } from './fixtures';
import Review from './Review';

const post = vi.fn();
vi.mock('@inertiajs/react', async (importActual) => ({ ...(await importActual<object>()), router: { post: (...args: unknown[]) => post(...args), get: vi.fn() } }));

const page = (overrides = {}) => ({
    ...shell({ tab: 'review' as const }),
    queue: { rows: queueFixture, meta: { total: 3, perPage: 50, currentPage: 1, lastPage: 1 } },
    filters: { locale: 'es', reason: null, namespace: null, group: null, search: null },
    options: { locales: [{ code: 'es', name: 'Spanish' }], reasons: ['draft', 'flagged', 'pending', 'stale', 'held'] as const },
    preview: { clean: 1, warnings: 0, errors: 1 },
    ...overrides,
});

describe('Translations review', () => {
    beforeEach(() => { localStorage.clear(); post.mockClear(); });

    it('approves the selected rows with the fingerprints the page showed', () => {
        render(<TooltipProvider><Review {...page()} /></TooltipProvider>);
        fireEvent.click(screen.getAllByRole('checkbox', { name: /select/i })[0]);
        fireEvent.click(screen.getByRole('button', { name: 'Approve 1 selected' }));

        expect(post).toHaveBeenCalledWith(expect.stringContaining('/review/approve'), { items: { [queueFixture[0].translationId!]: queueFixture[0].fingerprint } }, expect.anything());
    });

    it('confirms approve-all with the preview counts before posting', () => {
        render(<TooltipProvider><Review {...page()} /></TooltipProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Approve everything clean in this view' }));

        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('1 will be approved. Left for you: 0 with warnings and 1 with errors.')).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Approve' }));
        expect(post).toHaveBeenCalledWith(expect.stringContaining('/review/approve-matching'), expect.objectContaining({ locale: 'es', includeWarnings: false }), expect.anything());
    });

    it('rejects with a required note', () => {
        render(<TooltipProvider><Review {...page()} /></TooltipProvider>);
        fireEvent.click(screen.getAllByRole('checkbox', { name: /select/i })[1]);
        fireEvent.click(screen.getByRole('button', { name: 'Reject selected' }));
        fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reject' }));
        expect(post).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText(/Why\?/), { target: { value: 'Too stiff.' } });
        fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reject' }));
        expect(post).toHaveBeenCalledWith(expect.stringContaining('/review/reject'), expect.objectContaining({ note: 'Too stiff.' }), expect.anything());
    });

    it('shows one item at a time in focus mode, with the English change, and approves with the keyboard', () => {
        render(<TooltipProvider><Review {...page()} /></TooltipProvider>);
        fireEvent.click(screen.getByRole('radio', { name: 'Focus' }));

        expect(screen.getByText('1 of 3')).toBeTruthy();
        fireEvent.keyDown(window, { key: 's' });
        fireEvent.keyDown(window, { key: 's' });
        expect(screen.getByText('What changed in the English')).toBeTruthy();
        fireEvent.keyDown(window, { key: 'a' });
        expect(post).toHaveBeenCalledWith(expect.stringContaining('/review/approve'), { items: { [queueFixture[2].translationId!]: queueFixture[2].fingerprint } }, expect.anything());
    });

    it('disables actions when not editable', () => {
        render(<TooltipProvider><Review {...page({ viewer: { translates: ['es'], reviews: ['es'], manages: false, editable: false } })} /></TooltipProvider>);

        expect((screen.getByRole('button', { name: 'Approve everything clean in this view' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('says when nothing needs the person', () => {
        render(<TooltipProvider><Review {...page({ queue: { rows: [], meta: { total: 0, perPage: 50, currentPage: 1, lastPage: 1 } } })} /></TooltipProvider>);

        expect(screen.getByText('Nothing needs you in Spanish.')).toBeTruthy();
    });
});
```

- [ ] **Step 3: Run the test and watch it fail**

Run: `npx vitest run resources/js/modules/bridge/pages/Translations/Review.test.tsx`
Expected: FAIL, because `./Review` can't be resolved.

- [ ] **Step 4: Implement**

**`Review.tsx`:**
- It renders `TabBar`, the read-only note when `!viewer.editable`, a filter bar, `ViewSwitch` over `['table', 'focus']` (`useRememberedView('review', …, 'table')`), then `QueueTable` or `FocusView`.
- **The filter bar:** a language `<select>` (with "All" when there's more than one), a reason `<select>`, and a search input. Changing any of them calls `router.get(review({ query }).url, …, { preserveState: true })`.
- **Empty queue:** show `copy.review.empty` filled with the filtered language's name, or `emptyAll`, plus a link to `keys().url`.

**`QueueTable.tsx`:**
- **Rows:** a row per item, with a checkbox labelled "Select {keyRef}" (disabled when `translationId === null` or `!viewer.canReview`-style logic: the item's locale isn't in `viewer.reviews`); the key; a reason badge; the English; the candidate (or `approved` when `candidate` is null); and the age.
- **Actions bar:**
  - "Approve :count selected" posts `approve()` with `{ items: {id: fingerprint} }` from the ticked rows;
  - "Reject selected" opens `NoteDialog`, then posts `reject()` with `{ items, note }`;
  - "Ask the AI again for selected" fetches `estimate()` with the ticked items' refs grouped by locale, shows `batch.estimate` with the sum of input and output in a confirm, then posts `redraft()`;
  - "Approve everything clean in this view" opens a confirm dialog showing `batch.confirmAll` filled from `preview`, plus an `includeWarnings` checkbox (shown only when `preview.warnings > 0`), then posts `approveMatching()` with `{ ...filters, includeWarnings }`.
- **Read-only:** every action button is disabled when `!viewer.editable`.
- **Post options:** every post uses `{ preserveScroll: true }`.
- **Paging:** when `meta.lastPage > 1`, show pages using the same pager component `DataTable` uses.

**`FocusView.tsx`:**
- **Position:** an index into `rows` (local state), with `focusPosition` shown as "1 of 3".
- **Layout:** two columns. The left column shows the English; for an update, its heading is `changed` and it shows `previousSource` → `source` with the diff rendered. The diff tokens are `[-x-]` (removed) and `{+x+}` (added): split them with a regex, and render removals as `<del>` and additions as `<ins>`, never through `dangerouslySetInnerHTML`. The right column shows the candidate, or the approved value, with its issues listed.
- **Buttons:**
  - Approve posts `approve()` with this item's `{id: fingerprint}`, then moves to the next item;
  - Edit opens an inline textarea with Save and "Save and approve" (the latter only when the item's locale is in `viewer.reviews`), posting `edit({ translation: id })` with `{ value, approve, fingerprint }`;
  - Reject opens `NoteDialog`;
  - "Ask the AI again" goes through the same estimate confirm;
  - Skip moves to the next item.
- **Keyboard:** a `keydown` listener on `window` maps `a` to approve, `e` to edit, `r` to reject and `s` to skip, ignored while focus is inside an input or textarea. Remove it on unmount.
- **Read-only:** every action is disabled when `!viewer.editable`.

**`NoteDialog.tsx`:** a `role="dialog"` with a labelled textarea (`review.note.label`) and Reject and Cancel buttons. Reject doesn't submit while the note is empty; it shows `review.note.required` instead. Use the same dialog primitive the Locales dialog uses (`resources/js/modules/bridge/components/LocaleDialog/LocaleDialog.tsx`).

- [ ] **Step 5: Run the test and watch it pass, then the checks**

Run: `npx vitest run resources/js/modules/bridge/pages/Translations`
Expected: PASS, the Overview's 4 tests and Review's 6.

Run: `npm run -s types:check && npx vp check`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add resources/js/modules/bridge/pages/Translations
git commit -m "feat(bridge): the review page: a table with batch actions and a one-at-a-time focus mode"
```

---

### Task 6: The Keys page's server side, and writing a missing value

**Files:**
- Create: `app/Modules/Bridge/Http/Controllers/Translations/KeysController.php`, `app/Modules/Bridge/Http/Controllers/Translations/WriteController.php`
- Modify: `app/Modules/Bridge/Routes/web.php` (the `keys` and `write` routes), `app/Modules/Bridge/Lang/en/translations.php` (the `keys` copy)
- Test: `tests/Feature/Modules/Bridge/Translations/KeysPageTest.php`

**Interfaces:**
- Consumes: `KeyBrowser::for(Viewer, array $filters, int $page, int $perPage)`, `matchCount(Viewer, array)`, `files(Viewer)` and `key(Viewer, int)`, with the constant `SEARCH_LIMIT`; `KeyRow::toArray()` (`keyId`, `keyRef`, `namespace`, `group`, `key`, `source`, `context`, and `cells: {locale: {status, value, candidate, approved, issues, translationId, fingerprint}}`); `KeyDetail::toArray()` (`row` and `history`); `ReviewService::write(string $keyRef, string $locale, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false)`.
- Produces:
  - the props of `bridge::Translations/Keys`:
    - `keys`: `{rows: KeyRow[], meta}`
    - `files`: `{namespace, group, keys, needsWork}[]`
    - `filters`: namespace, group, locale, status and search
    - `matchCount`: int
    - `searchLimit`: 500
    - `detail`: `KeyDetail | null`, from `?key={id}`
  - the POST body of `write`: `ref`, `locale`, `value` and `approve` (bool).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Modules/Bridge/Translations/KeysPageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Access\PermissionName;
use App\Models\User;
use Database\Seeders\Access\AccessSeeder;
use Database\Seeders\LocalesSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use LonelyLights\Prosetta\Queries\KeyFinder;

beforeEach(function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(AccessSeeder::class);
    translationsWorld();
    $this->reviewer = User::factory()->create();
    $this->reviewer->givePermissionTo([PermissionName::AccessBridge, 'translations.review.es']);
});

it('lists keys with a cell for each of the viewer\'s languages, and the file list', function () {
    $this->actingAs($this->reviewer)->get('/bridge/translations/keys')
        ->assertInertia(fn (Assert $page) => $page->component('bridge::Translations/Keys')
            ->has('keys.rows', 3)
            ->where('keys.rows.0.cells.es.status', 'approved')
            ->missing('keys.rows.0.cells.ar')
            ->where('files.0.group', 'demo')
            ->where('searchLimit', 500));
});

it('filters by status and search from the URL, and counts matches', function () {
    $this->actingAs($this->reviewer)->get('/bridge/translations/keys?status=missing')
        ->assertInertia(fn (Assert $page) => $page->has('keys.rows', 1)->where('keys.rows.0.keyRef', 'demo.color'));

    $this->actingAs($this->reviewer)->get('/bridge/translations/keys?search=GUARDADO')
        ->assertInertia(fn (Assert $page) => $page->where('matchCount', 1)->where('keys.rows.0.keyRef', 'demo.saved'));
});

it('sends a key\'s detail with its history when asked', function () {
    $id = app(KeyFinder::class)->find('demo.saved')->id;

    $this->actingAs($this->reviewer)->get("/bridge/translations/keys?key=$id")
        ->assertInertia(fn (Assert $page) => $page->where('detail.row.keyRef', 'demo.saved')->has('detail.history.es'));
});

it('writes a missing value as a candidate, or approved for a reviewer who asks', function () {
    config(['prosetta.review.editable' => true]);

    $this->actingAs($this->reviewer)->post('/bridge/translations/keys/write', ['ref' => 'demo.color', 'locale' => 'es', 'value' => 'Elige un color', 'approve' => true])
        ->assertRedirect();

    expect(app(KeyFinder::class)->find('demo.color')->translations()->where('locale', 'es')->first()->approved_value)->toBe('Elige un color');
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations/KeysPageTest.php`
Expected: FAIL, with a 404.

- [ ] **Step 3: Implement**

`KeysController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Bridge\Http\Controllers\Translations;

use App\Models\User;
use App\Modules\Bridge\Support\Translations\ReviewFilters;
use App\Modules\Bridge\Support\Translations\TranslationPages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LonelyLights\Prosetta\Queries\KeyBrowser;
use LonelyLights\Prosetta\Review\KeyRow;
use LonelyLights\Prosetta\Review\Viewer;

/** /bridge/translations/keys: every key, with the viewer's languages beside the English, as a matrix, a file, or search results. */
final readonly class KeysController {
    private const int PER_PAGE = 50;

    public function __construct(private TranslationPages $pages, private KeyBrowser $browser) {}

    public function __invoke(Request $request): Response {
        /** @var User $user */
        $user = $request->user();
        $viewer = Viewer::for($user);
        $filters = [...ReviewFilters::from($request), 'status' => is_string($status = $request->input('status')) && $status !== '' ? $status : null];
        unset($filters['reason']);
        $page = $this->browser->for($viewer, $filters, max(1, $request->integer('page', 1)), self::PER_PAGE);
        $detailId = $request->integer('key');

        return Inertia::render('bridge::Translations/Keys', [
            ...$this->pages->props($user, 'keys'),
            'keys' => [
                'rows' => array_map(fn (KeyRow $row) => $row->toArray(), $page->items()),
                'meta' => ['total' => $page->total(), 'perPage' => $page->perPage(), 'currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage()],
            ],
            'files' => $this->browser->files($viewer),
            'filters' => $filters,
            'matchCount' => $this->browser->matchCount($viewer, $filters),
            'searchLimit' => KeyBrowser::SEARCH_LIMIT,
            'detail' => $detailId > 0 ? $this->browser->key($viewer, $detailId)?->toArray() : null,
        ]);
    }
}
```

`WriteController.php`: validate `ref` (`required|string`), `locale` (`required|string`), `value` (`required|string|max:5000`) and `approve` (boolean). Then call `TranslationPages::respond(fn () => ['ref' => $ref, 'locale' => $locale, 'translationId' => app(ReviewService::class)->write($ref, $locale, $value, $user, null, $request->boolean('approve'))->getKey()], 'translations.write', __('bridge::translations.flash.edited'))`.

Create a placeholder page, `resources/js/modules/bridge/pages/Translations/Keys.tsx`, containing `export default function Keys() { return null; }` with a one-line docblock. Inertia's `ensure_pages_exist` needs it; Task 7 replaces it.

Register both routes. Add a `keys` block to `translations.php`:

```php
    'keys' => [
        'title' => 'Keys',
        'filters' => ['namespace' => 'Module', 'group' => 'File', 'locale' => 'Language', 'status' => 'Status', 'search' => 'Search keys and text', 'all' => 'All'],
        'files' => 'Files',
        'needsWork' => ':count need work',
        'columns' => ['key' => 'Key', 'english' => 'English'],
        'matrixTooBig' => 'Choose a module or file to see the matrix.',
        'searchCapped' => 'Showing the first :limit of :count matches. Narrow the search to see the rest.',
        'writePlaceholder' => 'Missing: click to write',
        'history' => ['title' => 'History', 'empty' => 'No history yet.'],
        'empty' => 'No keys match.',
    ],
```

- [ ] **Step 4: Run the tests and watch them pass, then the suite**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations`
Expected: PASS, 20 tests.

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact --parallel`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Bridge tests/Feature/Modules/Bridge/Translations
git commit -m "feat(bridge): the keys page's data: rows per language, files, filters, search count and history"
```

---

### Task 7: The Keys page (matrix, file editor, or search)

**Files:**
- Create in `resources/js/modules/bridge/pages/Translations/`: `Keys.tsx`, `components/KeyMatrix.tsx`, `components/FileEditor.tsx`, `components/SearchResults.tsx`, `components/KeyHistory.tsx`
- Modify: `types.ts` (`KeysPage`, `KeyRow`, `KeyCell`), `fixtures.ts` (`keysFixture`, and the `keys` copy)
- Test: `resources/js/modules/bridge/pages/Translations/Keys.test.tsx`

**Interfaces:**
- Consumes: Task 6's props; Wayfinder `keys`, `write` and `edit`; `useRememberedView`, `TabBar` and `ViewSwitch`.
- Produces: the `bridge::Translations/Keys` page.

- [ ] **Step 1: Types and fixtures**

Add to `types.ts`:

```ts
export interface KeyCell { status: CellStatus; value: string | null; candidate: string | null; approved: string | null; issues: QueueItem['issues']; translationId: number | null; fingerprint: string | null }
export interface KeyRow { keyId: number; keyRef: string; namespace: string; group: string; key: string; source: string; context: string | null; cells: Record<string, KeyCell> }
export interface KeysPage extends TranslationsShell {
    keys: { rows: KeyRow[]; meta: { total: number; perPage: number; currentPage: number; lastPage: number } };
    files: { namespace: string; group: string; keys: number; needsWork: number }[];
    filters: { namespace: string | null; group: string | null; locale: string | null; status: string | null; search: string | null };
    matchCount: number;
    searchLimit: number;
    detail: { row: KeyRow; history: Record<string, { action: string; reviewer: string | null; previous: string | null; new: string | null; notes: string | null; at: string }[]> } | null;
}
```

In `fixtures.ts`, export `keysFixture: KeyRow[]` with three rows of the `*` namespace and `demo` group, each with `es` and `ar` cells:
1. `demo.greeting`: both approved;
2. `demo.saved`: `es` approved, `ar` flagged;
3. `demo.color`: `es` missing, `ar` stale.

Also add the `keys` copy from Task 6.

- [ ] **Step 2: Write the failing test**

`Keys.test.tsx`:

```tsx
// @vitest-environment happy-dom
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/primitives/tooltip';
import { keysFixture, shell } from './fixtures';
import Keys from './Keys';

const post = vi.fn();
vi.mock('@inertiajs/react', async (importActual) => ({ ...(await importActual<object>()), router: { post: (...args: unknown[]) => post(...args), get: vi.fn() } }));

const page = (overrides = {}) => ({
    ...shell({ tab: 'keys' as const }),
    keys: { rows: keysFixture, meta: { total: 3, perPage: 50, currentPage: 1, lastPage: 1 } },
    files: [{ namespace: '*', group: 'demo', keys: 3, needsWork: 2 }],
    filters: { namespace: '*', group: 'demo', locale: null, status: null, search: null },
    matchCount: 3, searchLimit: 500, detail: null,
    ...overrides,
});

describe('Translations keys', () => {
    beforeEach(() => { localStorage.clear(); post.mockClear(); });

    it('shows a matrix of status cells per language', () => {
        render(<TooltipProvider><Keys {...page()} /></TooltipProvider>);

        const row = screen.getByRole('row', { name: /demo\.saved/ });
        expect(within(row).getByLabelText('ar: Flagged')).toBeTruthy();
    });

    it('opens the file editor and writes a missing value as a candidate', () => {
        render(<TooltipProvider><Keys {...page()} /></TooltipProvider>);
        fireEvent.click(screen.getByRole('radio', { name: 'File' }));
        fireEvent.click(screen.getByRole('button', { name: 'Missing: click to write' }));
        fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Elige un color' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(post).toHaveBeenCalledWith(expect.stringContaining('/keys/write'), expect.objectContaining({ ref: 'demo.color', locale: 'es', value: 'Elige un color', approve: false }), expect.anything());
    });

    it('renders search text safely, and says when results are capped', () => {
        const hostile = { ...keysFixture[0], source: '<script>alert(1)</script> 100%_off' };
        render(<TooltipProvider><Keys {...page({ keys: { rows: [hostile], meta: { total: 1, perPage: 50, currentPage: 1, lastPage: 1 } }, filters: { namespace: null, group: null, locale: null, status: null, search: '100%_off' }, matchCount: 812 })} /></TooltipProvider>);
        fireEvent.click(screen.getByRole('radio', { name: 'Search' }));

        expect(screen.getByText(/<script>alert\(1\)<\/script> 100%_off/)).toBeTruthy();
        expect(document.querySelector('script')).toBeNull();
        expect(screen.getByText('Showing the first 500 of 812 matches. Narrow the search to see the rest.')).toBeTruthy();
    });

    it('asks to narrow the matrix when no module or file is chosen', () => {
        render(<TooltipProvider><Keys {...page({ filters: { namespace: null, group: null, locale: null, status: null, search: null } })} /></TooltipProvider>);

        expect(screen.getByText('Choose a module or file to see the matrix.')).toBeTruthy();
    });
});
```

- [ ] **Step 3: Run the test and watch it fail**

Run: `npx vitest run resources/js/modules/bridge/pages/Translations/Keys.test.tsx`
Expected: FAIL, because `./Keys` can't be resolved.

- [ ] **Step 4: Implement**

**`Keys.tsx`:**
- It renders `TabBar`, the read-only note, a filter bar (module and file selects built from `files`, language, status, and a search input, each driving `router.get(keys({ query }).url, …, { preserveState: true })`), and `ViewSwitch` over `['matrix', 'file', 'search']` (`useRememberedView('keys', …, 'matrix')`).
- When `filters.search` is set, it switches to the search view.
- It renders `KeyHistory` beside the list when `detail` isn't null.

**`KeyMatrix.tsx`:**
- **Narrowing:** when neither `filters.namespace` nor `filters.group` is set, render only `keys.matrixTooBig`.
- **Rows:** otherwise, a `<table>` with a row per key. The first column holds the key ref. Its `<th scope="row">` gives the row its accessible name.
- **Cells:** then the English, then one cell per language. Each cell is a colored square with `aria-label="{code}: {status label}"` and a `title` of its value. Clicking a cell switches to the file view at that key and language.

**`FileEditor.tsx`:**
- **Layout:** the file's keys in order, with the English beside one chosen language: a language `<select>` defaulting to the first of `viewer.translates`.
- **Missing values:** a missing cell is a button labelled `keys.writePlaceholder`. It opens an inline textarea with Save, plus "Save and approve" when the language is in `viewer.reviews`. Save posts `write()` with `{ ref, locale, value, approve }`.
- **Existing values:** a cell with a value opens the same editor, posting `edit({ translation: translationId })` with `{ value, approve, fingerprint }`.
- **Read-only:** disabled when `!viewer.editable`.

**`SearchResults.tsx`:**
- **Cards:** a card per row showing the key ref, the English and each language's value with its status label, as plain text nodes only, and a History link to `keys({ query: { ...filters, key: keyId } }).url`.
- **Capped results:** when `matchCount > searchLimit`, show `keys.searchCapped`, filled with the limit and the count.

**`KeyHistory.tsx`:** for each language in `detail.history`, a list of `{action} · {reviewer ?? 'system'} · {at}`, with the notes. Show `keys.history.empty` when a list is empty.

- [ ] **Step 5: Run the test and watch it pass, then the checks**

Run: `npx vitest run resources/js/modules/bridge/pages/Translations`
Expected: PASS, the Overview's 4 tests, Review's 6 and Keys' 4.

Run: `npm run -s types:check && npx vp check`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add resources/js/modules/bridge/pages/Translations
git commit -m "feat(bridge): the keys page: a status matrix, a file editor and search, with history"
```

---

### Task 8: End to end, and ready for the visual pass

**Files:**
- Create: `tests/Feature/Modules/Bridge/Translations/EndToEndTest.php`
- Modify: `app/Modules/Bridge/README.md` (a short "Translations" entry, if the README lists sections)

- [ ] **Step 1: Write the end-to-end test**

```php
<?php

declare(strict_types=1);

use App\Enums\Access\PermissionName;
use App\Models\User;
use Database\Seeders\Access\AccessSeeder;
use Database\Seeders\LocalesSeeder;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;

it('fixes a flagged draft in focus mode and exports it', function () {
    $this->seed(LocalesSeeder::class);
    $this->seed(AccessSeeder::class);
    $dir = translationsWorld();
    config(['prosetta.review.editable' => true]);
    $key = app(KeyFinder::class)->find('demo.color');
    $draft = Translation::query()->create([
        'key_id' => $key->id, 'locale' => 'es', 'value' => 'Escoge color', 'source_hash' => $key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => 'ai', 'issues' => [['code' => 'glossary_missing', 'severity' => 'warning', 'message' => 'x']],
    ]);
    $manager = User::factory()->create();
    $manager->givePermissionTo([PermissionName::AccessBridge, 'translations.manage']);
    $this->artisan('prosetta:export')->assertSuccessful();

    $this->actingAs($manager)->post("/bridge/translations/review/{$draft->id}/edit", ['value' => 'Elige un color', 'approve' => true, 'fingerprint' => ReviewService::fingerprint($draft)])->assertRedirect();
    $this->actingAs($manager)->post('/bridge/translations/operations/export')->assertRedirect();

    expect(require $dir.'/lang/es/demo.php')->toMatchArray(['color' => 'Elige un color']);
});
```

- [ ] **Step 2: Run it and the full suite**

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact tests/Feature/Modules/Bridge/Translations/EndToEndTest.php`
Expected: PASS. Every piece it uses exists after Tasks 1–7, so it passes on its first run. It's the integration check, not a red-green step.

Run: `DB_DATABASE=undaunted_test_gb php artisan test --compact --parallel`, then `npx vitest run resources/js/modules/bridge/pages/Translations`, `npm run -s types:check`, `npx vp check` and `composer types:check`.
Expected: all green. PHPStan passes with no new errors.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Modules/Bridge/Translations app/Modules/Bridge/README.md
git commit -m "test(bridge): translations end to end, from a flagged draft to the exported file"
```

The visual pass against Bridge's real look comes after the merge, iterated with the owner in the brainstorming companion.
