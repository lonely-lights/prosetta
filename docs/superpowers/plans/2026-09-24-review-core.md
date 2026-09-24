# Review Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Prosetta a headless review core that Undaunted's Bridge pages use now, and a Filament panel and JSON API routes can reuse later. The core covers:
- who can see and do what;
- what needs a person;
- the keys and their per-language status;
- coverage and health;
- batch actions with conflict checks;
- a guard against writes in production.

**Architecture:**
- **Permissions:** a `Review\Viewer` resolves a user's abilities once through the existing `Authorizer`.
- **Queries:** `ReviewQueue::for`, `KeyBrowser` and `Coverage` compute each key and language's state in PHP through one shared rule (`Review\Status`). That rule reuses `WorkState`, `CycleFailures` and a new `Rejections` store, so the UI and the cycle never disagree.
- **Writes:** writes stay in `ReviewService`, which gains:
  - an editable guard (`ReviewLocked`), for people only, never the system;
  - fingerprint conflict checks (`ReviewConflict`);
  - rejection notes that feed the next AI draft, and hold a key after two rejections.
- **Batches and operations:** a `Review\ReviewDesk` wraps batch approval, rejection, re-drafting and a queued cycle.
- **Results:** every result is a small readonly object with `toArray()`.

**Tech Stack:** PHP 8.3+, Laravel 11–13 components, Pest 5 with Orchestra Testbench 11. Tests use SQLite in memory through the package's `TestCase`.

**Spec:** `docs/superpowers/specs/2026-09-24-translation-review-design.md`, §2 and §4–§7. Plan 2 covers the Bridge pages (spec §3) and is written once this plan has landed.

## Global Constraints

- **Where to work:** in a git worktree on branch `feat/review-core`, at `C:\Websites\packages\prosetta-worktrees\review-core`. Copy the main folder's `composer.lock` in and run `composer install` first. Never edit `C:\Websites\packages\prosetta` directly. Undaunted uses that folder live, through a vendor link.
- **Git:** never run `git stash`. Commit after each task, ending the message with the trailers:
  ```
  Co-Authored-By: <model that wrote it> <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01H7Bfv1Q9QDoLknHiPpppw2
  ```
- **Code style:**
  - `declare(strict_types=1);`;
  - `final readonly` classes where possible;
  - comments as `# Title Case Comments` inside methods;
  - docblocks in plain sentences;
  - 4-space indent, with the opening brace on the same line as the class or function.
- **Test users:** with no hook, `Authorizer` allows only the `local` environment, and tests run as `testing`. So any test that acts as a user sets a hook first, for example `app(Authorizer::class)->using(fn () => true)`, as `ReviewServiceTest` does.
- **Test helpers:** Pest runs every test file in one process, so test helper functions need unique names or a `function_exists` guard.
- **The editable guard:** `prosetta.review.editable` defaults to `app()->environment('local', 'staging')` and can be overridden with `PROSETTA_REVIEW_EDITABLE`. It applies only when a user is given (`$by !== null`). The cycle, the commands and the queue pass no user and are never guarded.
- **Result objects:** every one has a `toArray()`, and results are never Eloquent models. They're what later API routes serialize.
- **Permissions:** Review implies Translate (`Authorizer::allows`); Manage covers sync, cycle and export. A query never returns a row for a language the viewer can't translate.
- **Rejection hold:** a key rejected twice from the same English is held (`Rejections::LIMIT = 2`).
- **Search cap:** keys-browser search is capped at 500 results (`KeyBrowser::SEARCH_LIMIT = 500`).
- **Test commands:** run `vendor/bin/pest` for the full suite after each task. It must end green (384 tests before this plan).

## Review Focus

1. **A reviewer of one language sees another language's data.** The queue, keys browser and coverage must each return only the viewer's languages. (Task 4 "hides languages the viewer can't translate"; Task 5 "shows only the viewer's languages in each row"; Task 6 "counts only the viewer's languages".)
2. **"Approve everything clean" approves something it shouldn't.** It must never approve an item with blocking issues, nor one with warnings unless asked, and it must report how many it skipped. (Task 7 "approves only clean items unless warnings are included".)
3. **Acting on a stale page.** An approval, edit or rejection made against a value that changed since the page loaded must be refused (for a single item) or skipped and counted (in a batch). (Task 2 tests; Task 7 "skips and counts conflicted items".)
4. **Production.** With the editable flag off, every human write is refused, while the cycle, running with no user, still approves. (Task 1 "refuses a person's approval when not editable, but lets the system approve".)
5. **Search text with `%`, `_` or mixed case** must match literally and ignore case. (Task 5 "searches literally and case-insensitively".)

---

## File Structure

**Prosetta, new:**
- `src/Review/Viewer.php`: the user and their abilities.
- `src/Review/Status.php`: one key and language's state (approved, draft, flagged, pending, stale, missing or held).
- `src/Review/QueueItem.php`: one row of the review queue.
- `src/Review/KeyRow.php`, `src/Review/KeyCell.php`, `src/Review/KeyDetail.php`: keys-browser results.
- `src/Review/BatchReport.php`: a batch action's counts.
- `src/Review/ReviewDesk.php`: batch approve and reject, re-draft, and a queued cycle.
- `src/Queries/KeyBrowser.php`: the keys browser.
- `src/Queries/Coverage.php` and `src/Queries/CoverageReport.php`: coverage and health.
- `src/Automation/Rejections.php`: a durable count of rejections per key and language.
- `src/Automation/Health.php`: the health checks, shared with `prosetta:health`.
- `src/Exceptions/ReviewLocked.php`, `src/Exceptions/ReviewConflict.php`.

**Prosetta, modified:**
- `config/prosetta.php` (`review.editable`);
- `src/Review/ReviewService.php` (guard, conflicts, rejections);
- `src/Review/ReviewQueue.php` (`for`, `count`, `all`);
- `src/ProsettaManager.php` (guard on sync, translate and export);
- `src/Automation/CycleWork.php` (rejection hold);
- `src/Automation/Cycle.php` (store the last report);
- `src/Translation/TranslationRunner.php` (rejection notes as feedback);
- `src/Resilience/UsageLedger.php` (a `sum` locale filter);
- `src/Console/HealthCommand.php` (uses `Health`);
- `tests/TestCase.php` (editable on in tests);
- `README.md`.

---

### Task 1: Viewer and the editable guard

**Files:**
- Modify: `config/prosetta.php` (the `review` block), `tests/TestCase.php`, `src/Review/ReviewService.php`, `src/ProsettaManager.php`
- Create: `src/Review/Viewer.php`, `src/Exceptions/ReviewLocked.php`
- Test: `tests/Feature/Review/ViewerTest.php`, `tests/Feature/Review/EditableGuardTest.php`

**Interfaces:**
- Produces:
  - `Viewer::for(?Authenticatable $user): Viewer`
  - `Viewer::editable(): bool` (static)
  - `$viewer->user`, `$viewer->translates` (`list<string>`), `$viewer->reviews` (`list<string>`), `$viewer->manages` (`bool`), `$viewer->isEditable` (`bool`)
  - `$viewer->canTranslate(string)`, `$viewer->canReview(string)`, `$viewer->locales(): list<string>`, `$viewer->toArray()`
  - `ReviewLocked` (extends `ProsettaException`)

- [ ] **Step 1: Turn editing on in the test environment**

In `tests/TestCase.php`, add this method to the class:

```php
    /** Testbench runs as "testing", where review writes are locked by default; the package's own tests edit freely. */
    protected function defineEnvironment($app): void {
        $app['config']->set('prosetta.review.editable', true);
    }
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Review/ViewerTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Review\Viewer;

beforeEach(function () {
    $this->seedLocales();
});

it('resolves which target languages a user can translate and review, and whether they manage', function () {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => match ($ability) {
        Ability::Review => $locale === 'es',
        Ability::Translate => $locale === 'ar',
        Ability::Manage => false,
    });

    $viewer = Viewer::for(new GenericUser(['id' => 'u1']));

    expect($viewer->reviews)->toBe(['es'])
        ->and($viewer->translates)->toEqualCanonicalizing(['es', 'ar'])
        ->and($viewer->manages)->toBeFalse()
        ->and($viewer->canReview('ar'))->toBeFalse()
        ->and($viewer->canTranslate('ar'))->toBeTrue()
        ->and($viewer->locales())->not->toContain('en', 'fr');
});

it('reads the editable flag, defaulting to local and staging only', function () {
    config(['prosetta.review.editable' => null]);
    expect(Viewer::editable())->toBeFalse();

    app()->detectEnvironment(fn () => 'staging');
    expect(Viewer::editable())->toBeTrue();

    config(['prosetta.review.editable' => 'false']);
    expect(Viewer::editable())->toBeFalse();
});
```

`tests/Feature/Review/EditableGuardTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\ProsettaManager;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

it('refuses a person\'s approval when not editable, but lets the system approve', function () {
    $translation = Translation::query()->where('locale', 'es')->first();
    $translation->update(['status' => TranslationStatus::Draft, 'value' => 'Borrador']);
    config(['prosetta.review.editable' => false]);

    expect(fn () => app(ReviewService::class)->approve([$translation->id], new GenericUser(['id' => 'u1'])))->toThrow(ReviewLocked::class);

    app(ReviewService::class)->approve([$translation->id], null);

    expect($translation->refresh()->status)->toBe(TranslationStatus::Approved);
});

it('refuses a person\'s edit, rejection and export when not editable', function () {
    $translation = Translation::query()->where('locale', 'es')->first();
    $user = new GenericUser(['id' => 'u1']);
    config(['prosetta.review.editable' => false]);

    expect(fn () => app(ReviewService::class)->edit($translation->id, 'Nuevo', $user))->toThrow(ReviewLocked::class)
        ->and(fn () => app(ReviewService::class)->reject($translation->id, $user))->toThrow(ReviewLocked::class)
        ->and(fn () => app(ProsettaManager::class)->export(by: $user))->toThrow(ReviewLocked::class);
});
```

- [ ] **Step 3: Run the tests and watch them fail**

Run: `vendor/bin/pest tests/Feature/Review/ViewerTest.php tests/Feature/Review/EditableGuardTest.php`
Expected: FAIL, with `Class "LonelyLights\Prosetta\Review\Viewer" not found` and `Class "LonelyLights\Prosetta\Exceptions\ReviewLocked" not found`.

- [ ] **Step 4: Implement**

In `config/prosetta.php`, replace the `review` block:

```php
    'review' => [
        'allow_self_approval' => true,
        # null: editable only in the local and staging environments. Writes made by a person
        # (never by the cycle or a command) are refused when this is false.
        'editable' => env('PROSETTA_REVIEW_EDITABLE'),
    ],
```

`src/Exceptions/ReviewLocked.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

/** A person tried to change translations where review is read-only (production, by default). */
final class ReviewLocked extends ProsettaException {
    public static function make(): self {
        return new self('Translations are read-only here: approvals made in this environment could never reach the lang files.');
    }
}
```

`src/Review/Viewer.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use Illuminate\Contracts\Auth\Authenticatable;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Enums\Ability;

/**
 * A person looking at translations, and what they may do: resolved once
 * through the Authorizer, then passed to every query and action so
 * filtering by permission happens in one place.
 */
final readonly class Viewer {
    /**
     * @param list<string> $translates target locales they may see and edit (Review implies Translate)
     * @param list<string> $reviews target locales they may approve and reject
     */
    public function __construct(
        public ?Authenticatable $user,
        public array $translates,
        public array $reviews,
        public bool $manages,
        public bool $isEditable,
    ) {}

    public static function for(?Authenticatable $user): self {
        $authorizer = app(Authorizer::class);
        $codes = array_map(fn (LocaleDescriptor $locale) => $locale->code, app(LocaleSource::class)->targets());

        return new self(
            $user,
            array_values(array_filter($codes, fn (string $code) => $authorizer->allows($user, Ability::Translate, $code))),
            array_values(array_filter($codes, fn (string $code) => $authorizer->allows($user, Ability::Review, $code))),
            $authorizer->allows($user, Ability::Manage),
            self::editable(),
        );
    }

    /** Whether people may change translations in this environment. */
    public static function editable(): bool {
        $configured = config('prosetta.review.editable');

        return $configured === null
            ? app()->environment('local', 'staging')
            : filter_var($configured, FILTER_VALIDATE_BOOL);
    }

    public function canTranslate(string $locale): bool {
        return in_array($locale, $this->translates, true);
    }

    public function canReview(string $locale): bool {
        return in_array($locale, $this->reviews, true);
    }

    /** @return list<string> the locales this viewer sees, in target order */
    public function locales(): array {
        return $this->translates;
    }

    /** @return array{translates: list<string>, reviews: list<string>, manages: bool, editable: bool} */
    public function toArray(): array {
        return ['translates' => $this->translates, 'reviews' => $this->reviews, 'manages' => $this->manages, 'editable' => $this->isEditable];
    }
}
```

In `src/Review/ReviewService.php`:
1. Add `use LonelyLights\Prosetta\Exceptions\ReviewLocked;`.
2. Add this private method:

```php
    /** People can't change translations where review is read-only; the system (no user) always can. */
    private function unlocked(?Authenticatable $by): void {
        if ($by !== null && ! Viewer::editable()) {
            throw ReviewLocked::make();
        }
    }
```

3. Call `$this->unlocked($by);` as the first line of `edit`, `write`, `approve`, `reject`, `confirm` and `approveClean`.

In `src/ProsettaManager.php`:
1. Add `use LonelyLights\Prosetta\Exceptions\ReviewLocked;` and `use LonelyLights\Prosetta\Review\Viewer;`.
2. In `sync`, `translate` and `export`, add this as the **first** line of the method, before any `authorize` call, so a read-only environment reports that rather than a permission error:

```php
        if ($by !== null && ! Viewer::editable()) {
            throw ReviewLocked::make();
        }
```

- [ ] **Step 5: Run the tests and watch them pass, then run the suite**

Run: `vendor/bin/pest tests/Feature/Review/ViewerTest.php tests/Feature/Review/EditableGuardTest.php`
Expected: PASS, 4 tests.

Run: `vendor/bin/pest`
Expected: PASS, 388 tests.

- [ ] **Step 6: Commit**

```bash
git add config/prosetta.php tests/TestCase.php src/Review/Viewer.php src/Exceptions/ReviewLocked.php src/Review/ReviewService.php src/ProsettaManager.php tests/Feature/Review/ViewerTest.php tests/Feature/Review/EditableGuardTest.php
git commit -m "Add a review Viewer and a guard that keeps people from changing translations where review is read-only"
```

---

### Task 2: Conflict checks by fingerprint

**Files:**
- Create: `src/Exceptions/ReviewConflict.php`
- Modify: `src/Review/ReviewService.php`
- Test: `tests/Feature/Review/ReviewConflictTest.php`

**Interfaces:**
- Consumes: `ReviewService` (Task 1).
- Produces:
  - `ReviewService::fingerprint(Translation $translation): string` (static)
  - `edit(int $translationId, string $value, ?Authenticatable $by, ?string $notes = null, bool $approve = false, ?string $expected = null)`
  - `reject(int $translationId, ?Authenticatable $by, ?string $notes = null, ?string $expected = null)`
  - `confirm(int $translationId, ?Authenticatable $by, ?string $notes = null, ?string $expected = null)`
  - `approve(int|array $translationIds, ?Authenticatable $by, ?string $notes = null, array $expected = [])`, where `$expected` maps id to fingerprint and a mismatch is skipped with reason `'conflict'`
  - `ReviewConflict` (extends `ProsettaException`) with `public int $translationId`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Review/ReviewConflictTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ReviewConflict;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    # With No Hook, Only the Local Environment Is Allowed; Tests Run as "testing"
    app(Authorizer::class)->using(fn () => true);
    $this->draft = Translation::query()->where('locale', 'es')->first();
    $this->draft->update(['status' => TranslationStatus::Draft, 'value' => 'Primera versión']);
});

it('refuses an edit or rejection made against a value that has changed since the page loaded', function () {
    $seen = ReviewService::fingerprint($this->draft->refresh());
    $this->draft->update(['value' => 'Cambiada por el ciclo']);
    $user = new GenericUser(['id' => 'u1']);

    expect(fn () => app(ReviewService::class)->edit($this->draft->id, 'Mía', $user, expected: $seen))->toThrow(ReviewConflict::class)
        ->and(fn () => app(ReviewService::class)->reject($this->draft->id, $user, 'no', expected: $seen))->toThrow(ReviewConflict::class)
        ->and($this->draft->refresh()->value)->toBe('Cambiada por el ciclo');
});

it('skips a changed item in a batch approval and approves the rest', function () {
    $other = Translation::query()->where('locale', 'es')->whereKeyNot($this->draft->id)->first();
    $other->update(['status' => TranslationStatus::Draft, 'value' => 'Otra']);
    $expected = [$this->draft->id => 'stale-fingerprint', $other->id => ReviewService::fingerprint($other->refresh())];

    $report = app(ReviewService::class)->approve([$this->draft->id, $other->id], new GenericUser(['id' => 'u1']), expected: $expected);

    expect($report->approved)->toBe([$other->id])
        ->and($report->skipped)->toBe([$this->draft->id => 'conflict']);
});

it('accepts an action whose fingerprint still matches', function () {
    $seen = ReviewService::fingerprint($this->draft->refresh());

    app(ReviewService::class)->edit($this->draft->id, 'Mía', new GenericUser(['id' => 'u1']), expected: $seen);

    expect($this->draft->refresh()->value)->toBe('Mía');
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/pest tests/Feature/Review/ReviewConflictTest.php`
Expected: FAIL, with `Class "LonelyLights\Prosetta\Exceptions\ReviewConflict" not found` or an unknown named parameter `expected`.

- [ ] **Step 3: Implement**

`src/Exceptions/ReviewConflict.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Exceptions;

/** The translation changed after the person opened it (the cycle updated it, or someone else acted first). */
final class ReviewConflict extends ProsettaException {
    public function __construct(public readonly int $translationId) {
        parent::__construct("Translation [$translationId] changed since you opened it.");
    }
}
```

In `src/Review/ReviewService.php`:
1. Add `use LonelyLights\Prosetta\Exceptions\ReviewConflict;` and `use LonelyLights\Prosetta\Support\Fingerprint;`.
2. Add these methods:

```php
    /** What a page saw of a translation: any change to its candidate, approval, status or English changes this. */
    public static function fingerprint(Translation $translation): string {
        return Fingerprint::of(json_encode([
            $translation->value, $translation->approved_value, $translation->status->value, $translation->source_hash, $translation->approved_source_hash,
        ], JSON_THROW_ON_ERROR));
    }

    private function current(Translation $translation, ?string $expected): void {
        if ($expected !== null && self::fingerprint($translation) !== $expected) {
            throw new ReviewConflict((int) $translation->getKey());
        }
    }
```

3. Add the trailing parameter `?string $expected = null` to `edit`, `reject` and `confirm`, and call `$this->current($translation, $expected);` right after each one loads `$translation`.
4. Give `approve` the trailing parameter `array $expected = []`, and add this as the first check inside its approval loop, before `refusal()`:

```php
            $id = (int) $translation->getKey();

            if (isset($expected[$id]) && self::fingerprint($translation) !== $expected[$id]) {
                $report->skipped[$id] = 'conflict';

                continue;
            }
```

5. Update `approve`'s docblock to say "`$expected` maps a translation id to the fingerprint the page saw; a changed one is skipped as 'conflict'."

- [ ] **Step 4: Run the tests and watch them pass, then run the suite**

Run: `vendor/bin/pest tests/Feature/Review/ReviewConflictTest.php`
Expected: PASS, 3 tests.

Run: `vendor/bin/pest`
Expected: PASS, 391 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Exceptions/ReviewConflict.php src/Review/ReviewService.php tests/Feature/Review/ReviewConflictTest.php
git commit -m "Refuse review actions made against a translation that changed since the page loaded"
```

---

### Task 3: Rejection notes feed the next draft; two rejections hold a key

**Files:**
- Create: `src/Automation/Rejections.php`
- Modify: `src/Review/ReviewService.php`, `src/Translation/TranslationRunner.php`, `src/Automation/CycleWork.php`
- Test: `tests/Feature/Review/RejectionTest.php`

**Interfaces:**
- Consumes: `ReviewService::reject` (Task 2's signature); `CycleWork::plan()`; `State::update`/`get`; `TranslationBatch` with its `feedback` constructor argument.
- Produces:
  - `Rejections::KEY = 'review.rejections'`, `Rejections::LIMIT = 2`
  - `$rejections->record(Translation $translation)`, `$rejections->clear(string $locale, int $keyId)`
  - `$rejections->all(): array<string, array<string, array{hash: string, count: int}>>`
  - `Rejections::held(array $all, string $locale, TranslationKey $key): bool` (static)
  - CycleWork held lines: `"{locale} {ref} (held: rejected twice by a reviewer; waiting for a person)"`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Review/RejectionTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Automation\CycleWork;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Locale;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
    Locale::query()->where('locale_initials', 'ar')->update(['auto_translate' => true]);
    app(Authorizer::class)->using(fn () => true);
    $this->key = app(KeyFinder::class)->find('auth.throttle');
    $this->draft = Translation::query()->create([
        'key_id' => $this->key->id, 'locale' => 'ar', 'value' => 'محاولات كثيرة.', 'source_hash' => $this->key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => 'ai',
    ]);
});

it('sends a rejection note to the model with the next draft of that key', function () {
    app(ReviewService::class)->reject($this->draft->id, new GenericUser(['id' => 'u1']), 'Too stiff; keep the :seconds placeholder and sound friendly.');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    app(TranslationRunner::class)->run('ar', [$this->key->id]);

    /** @var TranslationBatch $batch */
    $batch = $driver->calls[0];

    expect($batch->feedback[(string) $this->key->id][0])->toContain('Too stiff; keep the :seconds placeholder and sound friendly.');
});

it('holds a key the cycle would draft once it has been rejected twice from the same English', function () {
    $user = new GenericUser(['id' => 'u1']);
    app(ReviewService::class)->reject($this->draft->id, $user, 'No.');
    $this->draft->update(['status' => TranslationStatus::Draft, 'value' => 'محاولة ثانية.']);
    app(ReviewService::class)->reject($this->draft->id, $user, 'Still no.');

    $plan = app(CycleWork::class)->plan();

    expect($plan['work']['ar'] ?? [])->not->toContain([$this->key->id])
        ->and(collect($plan['work']['ar'] ?? [])->flatten()->all())->not->toContain($this->key->id)
        ->and($plan['held'])->toContain('ar auth.throttle (held: rejected twice by a reviewer; waiting for a person)');
});

it('releases the hold once a person writes or approves a value', function () {
    $user = new GenericUser(['id' => 'u1']);
    app(ReviewService::class)->reject($this->draft->id, $user, 'No.');
    app(ReviewService::class)->reject($this->draft->id, $user, 'Still no.');

    app(ReviewService::class)->edit($this->draft->id, 'حاولت كثيرًا. انتظر :seconds ثانية.', $user, approve: true);

    expect(app(Rejections::class)->all())->toBe([]);
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/pest tests/Feature/Review/RejectionTest.php`
Expected: FAIL, with `Class "LonelyLights\Prosetta\Automation\Rejections" not found`.

- [ ] **Step 3: Implement**

`src/Automation/Rejections.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\State;
use Throwable;

/**
 * How often reviewers rejected a key's candidate in one locale, kept in
 * prosetta_state under review.rejections as locale => key id => {hash, count}.
 * Once a key is rejected LIMIT times from the same English, the cycle stops
 * drafting it and waits for a person; an English edit starts the count again,
 * and a person's own value or an approval clears it.
 */
final readonly class Rejections {
    public const string KEY = 'review.rejections';

    public const int LIMIT = 2;

    /** @throws Throwable when the transaction fails */
    public function record(Translation $translation): void {
        $key = $translation->key;

        State::update(self::KEY, function (mixed $stored) use ($translation, $key): array {
            $map = is_array($stored) ? $stored : [];
            $entry = $map[$translation->locale][(string) $key->getKey()] ?? null;
            $count = is_array($entry) && ($entry['hash'] ?? null) === $key->source_hash ? (int) ($entry['count'] ?? 0) : 0;
            $map[$translation->locale][(string) $key->getKey()] = ['hash' => $key->source_hash, 'count' => $count + 1];

            return $map;
        }, []);
    }

    /** @throws Throwable when the transaction fails */
    public function clear(string $locale, int $keyId): void {
        if (! isset($this->all()[$locale][(string) $keyId])) {
            return;
        }

        State::update(self::KEY, function (mixed $stored) use ($locale, $keyId): ?array {
            $map = is_array($stored) ? $stored : [];
            unset($map[$locale][(string) $keyId]);

            if (($map[$locale] ?? null) === []) {
                unset($map[$locale]);
            }

            return $map === [] ? null : $map;
        }, []);
    }

    /** @return array<string, array<string, array{hash: string, count: int}>> */
    public function all(): array {
        $stored = State::get(self::KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, array<string, array{hash: string, count: int}>> $all from all() */
    public static function held(array $all, string $locale, TranslationKey $key): bool {
        $entry = $all[$locale][(string) $key->getKey()] ?? null;

        return is_array($entry) && ($entry['hash'] ?? null) === $key->source_hash && (int) ($entry['count'] ?? 0) >= self::LIMIT;
    }
}
```

In `src/Review/ReviewService.php`:
1. Add `use LonelyLights\Prosetta\Automation\Rejections;`.
2. Add `private Rejections $rejections,` as the last constructor argument.
3. In `reject`, after the transaction and before the event, add `$this->rejections->record($translation);`.
4. In `edit`, after the transaction, add `$this->rejections->clear($translation->locale, (int) $translation->key_id);`. This covers `write`, which goes through `edit`.
5. In `approve`, after each `markApproved` transaction, add `$this->rejections->clear($translation->locale, (int) $translation->key_id);`.

In `src/Translation/TranslationRunner.php`, in `run()`:
1. Just before `$source = $this->locales->source();`, collect the notes:

```php
        # A Reviewer's Rejection Note Goes to the Model With That Key's Next Draft
        $notes = [];

        foreach ($keys as $id => $key) {
            $current = $existing->get($id);

            if ($current !== null && $current->status === TranslationStatus::Rejected) {
                $review = $current->reviews()->where('action', ReviewAction::Rejected->value)->latest('id')->first();

                if ($review !== null && ($review->notes ?? '') !== '') {
                    $notes[(string) $id] = ["A reviewer rejected the previous translation (\"{$current->value}\"): {$review->notes}"];
                }
            }
        }
```

2. Change the batch construction to pass the notes as feedback:

```php
        $batch = new TranslationBatch($source, $target, LocaleCode::isVariantOf($locale, $source) ? $source : null, $this->model($locale), $items, $notes);
```

`TranslationStatus` and `ReviewAction` are already imported in the runner; add either import if it's missing.

In `src/Automation/CycleWork.php`:
1. Add `private Rejections $rejections` to the constructor after `$failures`.
2. In `plan()`, read `$rejections = $this->rejections->all();` next to `$failures`.
3. Add a branch after the `CycleFailures::capped` branch:

```php
                } elseif (Rejections::held($rejections, $locale, $key)) {
                    $held[] = "$ref (held: rejected twice by a reviewer; waiting for a person)";
```

- [ ] **Step 4: Run the tests and watch them pass, then run the suite**

Run: `vendor/bin/pest tests/Feature/Review/RejectionTest.php`
Expected: PASS, 3 tests.

Run: `vendor/bin/pest`
Expected: PASS, 394 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Automation/Rejections.php src/Review/ReviewService.php src/Translation/TranslationRunner.php src/Automation/CycleWork.php tests/Feature/Review/RejectionTest.php
git commit -m "Send a rejection note with the key's next draft, and hold a key after two rejections"
```

---

### Task 4: One status rule, and the review queue for a viewer

**Files:**
- Create: `src/Review/Status.php`, `src/Review/QueueItem.php`
- Modify: `src/Review/ReviewQueue.php`
- Test: `tests/Feature/Review/ReviewQueueForViewerTest.php`

**Interfaces:**
- Consumes: `Viewer` (Task 1), `ReviewService::fingerprint` (Task 2), `Rejections::held` (Task 3), `CycleFailures::capped`, `WorkState`, `SourceChange::diff`.
- Produces:
  - `Status::of(TranslationKey $key, ?Translation $translation, string $locale, array $failures, array $rejections): string`, one of `approved`, `draft`, `flagged`, `pending`, `stale`, `held` or `missing`
  - `Status::NEEDS_PERSON = ['draft', 'flagged', 'pending', 'stale', 'held']`
  - `ReviewQueue::for(Viewer $viewer, array $filters = [], int $page = 1, int $perPage = 50): LengthAwarePaginator<int, QueueItem>`
  - `ReviewQueue::count(Viewer $viewer, array $filters = []): int`
  - `ReviewQueue::all(Viewer $viewer, array $filters = []): list<QueueItem>`
  - Filters: `locale`, `reason`, `namespace`, `group` and `search` (all `?string`).
  - `QueueItem` public properties: `?int translationId`, `int keyId`, `string keyRef`, `string namespace`, `string group`, `string locale`, `string reason`, `string source`, `?string previousSource`, `?string diff`, `?string candidate`, `?string approved`, `list issues`, `bool blocking`, `bool warnings`, `?string origin`, `?string updatedAt`, `?string fingerprint`; plus `toArray()`.

Plan ruling (the spec lists four reasons): the reason `draft` is added, for a clean AI or derived draft awaiting approval. Hosts with `automation.approve = 'none'`, and manual `prosetta:translate` runs, leave those, and a person must be able to find them.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Review/ReviewQueueForViewerTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function queueDraft(string $ref, string $locale, string $value, string $origin = 'ai', ?array $issues = null): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->updateOrCreate(['key_id' => $key->id, 'locale' => $locale], [
        'value' => $value, 'source_hash' => $key->source_hash, 'status' => TranslationStatus::Draft, 'origin' => $origin, 'issues' => $issues,
    ]);
}

function queueViewer(array $translate): Viewer {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability !== Ability::Manage && in_array($locale, $translate, true));

    return Viewer::for(new GenericUser(['id' => 'u1']));
}

it('gives each item that needs a person its reason', function () {
    queueDraft('auth.throttle', 'es', 'Demasiados intentos.');
    queueDraft('messages.welcome', 'es', '¡Hola, :nombre!', issues: [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'Missing :name.']]);
    queueDraft('messages.apples', 'es', '{0} Ninguna|{1} Una|[2,*] :count manzanas', origin: 'manual');
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'Those details do not match.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    $reasons = collect(app(ReviewQueue::class)->all(queueViewer(['es'])))->pluck('reason', 'keyRef')->all();

    expect($reasons)->toMatchArray([
        'auth.throttle' => 'draft',
        'messages.welcome' => 'flagged',
        'messages.apples' => 'pending',
        'auth.failed' => 'stale',
    ]);
});

it('hides languages the viewer can\'t translate', function () {
    queueDraft('auth.throttle', 'es', 'Demasiados intentos.');
    queueDraft('auth.throttle', 'ar', 'محاولات كثيرة.');

    $locales = collect(app(ReviewQueue::class)->all(queueViewer(['es'])))->pluck('locale')->unique()->values()->all();

    expect($locales)->toBe(['es']);
});

it('filters by reason and search, and pages', function () {
    queueDraft('auth.throttle', 'es', 'Demasiados intentos.');
    queueDraft('messages.welcome', 'es', 'Hola', issues: [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'x']]);
    $viewer = queueViewer(['es']);

    expect(app(ReviewQueue::class)->count($viewer, ['reason' => 'flagged']))->toBe(1)
        ->and(app(ReviewQueue::class)->count($viewer, ['search' => 'DEMASIADOS']))->toBe(1)
        ->and(app(ReviewQueue::class)->for($viewer, [], 1, 1)->total())->toBe(2)
        ->and(app(ReviewQueue::class)->for($viewer, [], 1, 1)->items())->toHaveCount(1);
});

it('shows the English an update was made from, with the word diff', function () {
    $failed = Translation::query()->where('locale', 'es')->whereHas('key', fn ($q) => $q->where('key', 'failed'))->first();
    $failed->update(['approved_source_value' => 'These credentials do not match our records.']);
    file_put_contents($this->fixture.'/lang/en/auth.php', "<?php return ['failed' => 'These details do not match our records.', 'throttle' => 'Too many login attempts. Please try again in :seconds seconds.'];");
    app(Syncer::class)->sync();

    $item = collect(app(ReviewQueue::class)->all(queueViewer(['es'])))->firstWhere('keyRef', 'auth.failed');

    expect($item->previousSource)->toBe('These credentials do not match our records.')
        ->and($item->diff)->toContain('credentials')->toContain('details')
        ->and($item->toArray())->toHaveKeys(['keyRef', 'reason', 'fingerprint']);
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/pest tests/Feature/Review/ReviewQueueForViewerTest.php`
Expected: FAIL, with `Call to undefined method LonelyLights\Prosetta\Review\ReviewQueue::all()`.

- [ ] **Step 3: Implement**

`src/Review/Status.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Support\WorkState;

/**
 * One key's state in one locale, by the same rules the cycle uses. Every
 * review screen reads this, so the pages and the cycle never disagree.
 */
final readonly class Status {
    /** @var list<string> the states a person should look at */
    public const array NEEDS_PERSON = ['draft', 'flagged', 'pending', 'stale', 'held'];

    /**
     * @param array<string, array<string, array{hash: string, count: int}>> $failures from CycleFailures::all()
     * @param array<string, array<string, array{hash: string, count: int}>> $rejections from Rejections::all()
     */
    public static function of(TranslationKey $key, ?Translation $translation, string $locale, array $failures, array $rejections): string {
        if (CycleFailures::capped($failures, $locale, $key) || Rejections::held($rejections, $locale, $key)) {
            return 'held';
        }

        if (WorkState::hasCurrentCandidate($key, $translation)) {
            /** @var Translation $translation */
            if (! in_array($translation->origin, [TranslationOrigin::Ai, TranslationOrigin::Derived], true)) {
                return 'pending';
            }

            return empty($translation->issues) ? 'draft' : 'flagged';
        }

        if (WorkState::isStale($key, $translation)) {
            return 'stale';
        }

        return WorkState::isMissing($key, $translation) ? 'missing' : 'approved';
    }
}
```

`src/Review/QueueItem.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Translation\SourceChange;

/** One thing a person should look at in the review queue, ready for Inertia props or JSON. */
final readonly class QueueItem {
    /** @param list<array{code: string, severity: string, message: string}> $issues */
    public function __construct(
        public ?int $translationId,
        public int $keyId,
        public string $keyRef,
        public string $namespace,
        public string $group,
        public string $locale,
        public string $reason,
        public string $source,
        public ?string $previousSource,
        public ?string $diff,
        public ?string $candidate,
        public ?string $approved,
        public array $issues,
        public bool $blocking,
        public bool $warnings,
        public ?string $origin,
        public ?string $updatedAt,
        public ?string $fingerprint,
    ) {}

    public static function from(TranslationKey $key, ?Translation $translation, string $locale, string $reason): self {
        $previous = $translation?->approved_source_value;
        $previous = $previous !== null && $previous !== $key->source_value ? $previous : null;
        $issues = $translation?->issues ?? [];

        return new self(
            $translation === null ? null : (int) $translation->getKey(),
            (int) $key->getKey(),
            $key->ref()->toString(),
            $key->file->namespace,
            $key->file->group,
            $locale,
            $reason,
            $key->source_value,
            $previous,
            $previous === null ? null : SourceChange::diff($previous, $key->source_value),
            $translation?->value,
            $translation?->approved_value,
            $issues,
            $translation?->hasBlockingIssues() ?? false,
            collect($issues)->contains(fn (array $issue) => ($issue['severity'] ?? '') === 'warning'),
            $translation?->origin->value,
            $translation?->updated_at?->toIso8601String(),
            $translation === null ? null : ReviewService::fingerprint($translation),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
```

In `src/Review/ReviewQueue.php`:
1. Add the imports:

```php
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Rejections;
```

2. Add a constructor:

```php
    public function __construct(private CycleFailures $failures, private Rejections $rejections) {}
```

3. Add these methods, keeping `forLocale` and `missing` unchanged:

```php
    /**
     * What needs a person, across the viewer's languages.
     *
     * @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters
     * @return LengthAwarePaginator<int, QueueItem>
     */
    public function for(Viewer $viewer, array $filters = [], int $page = 1, int $perPage = 50): LengthAwarePaginator {
        $items = $this->all($viewer, $filters);

        return new Paginator(array_slice($items, ($page - 1) * $perPage, $perPage), count($items), $perPage, $page);
    }

    /** @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters */
    public function count(Viewer $viewer, array $filters = []): int {
        return count($this->all($viewer, $filters));
    }

    /**
     * @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters
     * @return list<QueueItem>
     */
    public function all(Viewer $viewer, array $filters = []): array {
        $locales = array_values(array_filter($viewer->locales(), fn (string $code) => ($filters['locale'] ?? null) === null || $code === $filters['locale']));
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');
        $keys = $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get()
            ->filter(fn (TranslationKey $key) => (($filters['namespace'] ?? null) === null || $key->file->namespace === $filters['namespace'])
                && (($filters['group'] ?? null) === null || $key->file->group === $filters['group']))
            ->sortBy(fn (TranslationKey $key) => [$key->file->namespace, $key->file->group, $key->getKey()])
            ->values();
        $failures = $this->failures->all();
        $rejections = $this->rejections->all();
        $search = mb_strtolower((string) ($filters['search'] ?? ''));
        $items = [];

        foreach ($locales as $locale) {
            $translations = $translationModel::query()->where('locale', $locale)->whereIn('key_id', $keys->modelKeys())->get()->keyBy('key_id');

            foreach ($keys as $key) {
                $translation = $translations->get($key->getKey());
                $reason = Status::of($key, $translation, $locale, $failures, $rejections);

                if (! in_array($reason, Status::NEEDS_PERSON, true) || (($filters['reason'] ?? null) !== null && $reason !== $filters['reason'])) {
                    continue;
                }

                $item = QueueItem::from($key, $translation, $locale, $reason);

                if ($search !== '' && ! str_contains(mb_strtolower(implode("\n", [$item->keyRef, $item->source, (string) $item->candidate, (string) $item->approved])), $search)) {
                    continue;
                }

                $items[] = $item;
            }
        }

        return $items;
    }
```

- [ ] **Step 4: Run the tests and watch them pass, then run the suite**

Run: `vendor/bin/pest tests/Feature/Review/ReviewQueueForViewerTest.php`
Expected: PASS, 4 tests.

Run: `vendor/bin/pest`
Expected: PASS, 398 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Review/Status.php src/Review/QueueItem.php src/Review/ReviewQueue.php tests/Feature/Review/ReviewQueueForViewerTest.php
git commit -m "Add one shared status rule and a review queue across the viewer's languages"
```

---

### Task 5: Keys browser

**Files:**
- Create: `src/Review/KeyCell.php`, `src/Review/KeyRow.php`, `src/Review/KeyDetail.php`, `src/Queries/KeyBrowser.php`
- Test: `tests/Feature/Review/KeyBrowserTest.php`

**Interfaces:**
- Consumes: `Viewer`, `Status::of`, `ReviewService::fingerprint`, `CycleFailures::all`, `Rejections::all`.
- Produces:
  - `KeyBrowser::SEARCH_LIMIT = 500`
  - `KeyBrowser::for(Viewer $viewer, array $filters = [], int $page = 1, int $perPage = 50): LengthAwarePaginator<int, KeyRow>`, with filters `namespace`, `group`, `locale`, `status` and `search`
  - `KeyBrowser::matchCount(Viewer $viewer, array $filters = []): int` (before the search cap)
  - `KeyBrowser::files(Viewer $viewer): list<array{namespace: string, group: string, keys: int, needsWork: int}>`
  - `KeyBrowser::key(Viewer $viewer, int $keyId): ?KeyDetail`
  - `KeyRow`: `int keyId`, `string keyRef`, `string namespace`, `string group`, `string key`, `string source`, `?string context`, and `array<string, KeyCell> cells`
  - `KeyCell`: `string status`, `?string value`, `?string candidate`, `?string approved`, `list issues`, `?int translationId`, `?string fingerprint`
  - `KeyDetail`: `KeyRow row`, and `array<string, list<array{action: string, reviewer: ?string, previous: ?string, new: ?string, notes: ?string, at: string}>> history`
  - Each has `toArray()`.

Plan ruling: "source order" is key id order within a file. Sync creates keys in the order it reads them and appends later additions, so it's the source order except for keys added to the middle of an existing file. Reading the files on every request isn't worth that one difference.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Review/KeyBrowserTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Queries\KeyBrowser;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function browserViewer(array $translate): Viewer {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability !== Ability::Manage && in_array($locale, $translate, true));

    return Viewer::for(new GenericUser(['id' => 'u1']));
}

it('shows only the viewer\'s languages in each row, with each cell\'s status', function () {
    $row = collect(app(KeyBrowser::class)->for(browserViewer(['es']), ['group' => 'auth'])->items())->firstWhere('keyRef', 'auth.failed');

    expect(array_keys($row->cells))->toBe(['es'])
        ->and($row->cells['es']->status)->toBe('approved')
        ->and($row->cells['es']->value)->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('reports missing, and filters rows by a cell status', function () {
    $viewer = browserViewer(['es', 'ar']);
    $rows = collect(app(KeyBrowser::class)->for($viewer, ['group' => 'auth', 'status' => 'missing'])->items());

    expect($rows->pluck('keyRef')->all())->toContain('auth.throttle')
        ->and($rows->firstWhere('keyRef', 'auth.throttle')->cells['ar']->status)->toBe('missing');
});

it('searches literally and case-insensitively, across keys, English and translations', function () {
    $viewer = browserViewer(['es']);

    expect(collect(app(KeyBrowser::class)->for($viewer, ['search' => 'CREDENCIALES'])->items())->pluck('keyRef')->all())->toBe(['auth.failed'])
        ->and(app(KeyBrowser::class)->matchCount($viewer, ['search' => '100%']))->toBe(0)
        ->and(app(KeyBrowser::class)->matchCount($viewer, ['search' => 'access_code']))->toBe(0);
});

it('caps search results at the search limit', function () {
    expect(KeyBrowser::SEARCH_LIMIT)->toBe(500);
});

it('lists files with how many of their keys need work, and gives a key\'s history', function () {
    $viewer = browserViewer(['es']);
    $key = app(KeyFinder::class)->find('auth.failed');
    $translation = $key->translations()->where('locale', 'es')->first();
    $translation->update(['status' => TranslationStatus::Draft, 'value' => 'Datos incorrectos.']);
    app(ReviewService::class)->approve([$translation->id], new GenericUser(['id' => 'u1']));

    $files = collect(app(KeyBrowser::class)->files($viewer));
    $detail = app(KeyBrowser::class)->key($viewer, $key->id);

    expect($files->firstWhere('group', 'auth'))->toMatchArray(['namespace' => '*', 'group' => 'auth', 'keys' => 2])
        ->and(collect($detail->history['es'])->pluck('action')->all())->toContain('approved')
        ->and($detail->toArray())->toHaveKeys(['row', 'history']);
});
```

The fixture's root namespace is `'*'` (`KeyRef::ROOT`). Step 1 of Step 4 below checks it before relying on it.

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/pest tests/Feature/Review/KeyBrowserTest.php`
Expected: FAIL, with `Class "LonelyLights\Prosetta\Queries\KeyBrowser" not found`.

- [ ] **Step 3: Implement**

`src/Review/KeyCell.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\Translation;

/** One key in one language, as a keys browser shows it. */
final readonly class KeyCell {
    /** @param list<array{code: string, severity: string, message: string}> $issues */
    public function __construct(
        public string $status,
        public ?string $value,
        public ?string $candidate,
        public ?string $approved,
        public array $issues,
        public ?int $translationId,
        public ?string $fingerprint,
    ) {}

    public static function from(?Translation $translation, string $status): self {
        return new self(
            $status,
            $translation?->approved_value ?? $translation?->value,
            $translation?->value,
            $translation?->approved_value,
            $translation?->issues ?? [],
            $translation === null ? null : (int) $translation->getKey(),
            $translation === null ? null : ReviewService::fingerprint($translation),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
```

`src/Review/KeyRow.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Models\TranslationKey;

/** One key with its English and a cell for each language the viewer can see. */
final readonly class KeyRow {
    /** @param array<string, KeyCell> $cells locale => cell */
    public function __construct(
        public int $keyId,
        public string $keyRef,
        public string $namespace,
        public string $group,
        public string $key,
        public string $source,
        public ?string $context,
        public array $cells,
    ) {}

    /** @param array<string, KeyCell> $cells */
    public static function from(TranslationKey $key, array $cells): self {
        return new self((int) $key->getKey(), $key->ref()->toString(), $key->file->namespace, $key->file->group, $key->key, $key->source_value, $key->context, $cells);
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [...get_object_vars($this), 'cells' => array_map(fn (KeyCell $cell) => $cell->toArray(), $this->cells)];
    }
}
```

`src/Review/KeyDetail.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

/** One key's row plus its review history in each of the viewer's languages. */
final readonly class KeyDetail {
    /** @param array<string, list<array{action: string, reviewer: ?string, previous: ?string, new: ?string, notes: ?string, at: string}>> $history */
    public function __construct(public KeyRow $row, public array $history) {}

    /** @return array{row: array<string, mixed>, history: array<string, list<array<string, ?string>>>} */
    public function toArray(): array {
        return ['row' => $this->row->toArray(), 'history' => $this->history];
    }
}
```

`src/Queries/KeyBrowser.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Models\TranslationReview;
use LonelyLights\Prosetta\Review\KeyCell;
use LonelyLights\Prosetta\Review\KeyDetail;
use LonelyLights\Prosetta\Review\KeyRow;
use LonelyLights\Prosetta\Review\Status;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Every current key with its English and a cell per language the viewer can
 * see: for a matrix, a file-by-file editor, or search results.
 */
final readonly class KeyBrowser {
    public const int SEARCH_LIMIT = 500;

    public function __construct(private CycleFailures $failures, private Rejections $rejections) {}

    /**
     * @param array{namespace?: ?string, group?: ?string, locale?: ?string, status?: ?string, search?: ?string} $filters
     * @return LengthAwarePaginator<int, KeyRow>
     */
    public function for(Viewer $viewer, array $filters = [], int $page = 1, int $perPage = 50): LengthAwarePaginator {
        $rows = $this->rows($viewer, $filters);

        if (($filters['search'] ?? '') !== '') {
            $rows = array_slice($rows, 0, self::SEARCH_LIMIT);
        }

        return new Paginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
    }

    /** @param array{namespace?: ?string, group?: ?string, locale?: ?string, status?: ?string, search?: ?string} $filters */
    public function matchCount(Viewer $viewer, array $filters = []): int {
        return count($this->rows($viewer, $filters));
    }

    /** @return list<array{namespace: string, group: string, keys: int, needsWork: int}> */
    public function files(Viewer $viewer): array {
        $files = [];

        foreach ($this->rows($viewer, []) as $row) {
            $file = $files[$row->namespace.'::'.$row->group] ??= ['namespace' => $row->namespace, 'group' => $row->group, 'keys' => 0, 'needsWork' => 0];
            $file['keys']++;

            if (collect($row->cells)->contains(fn (KeyCell $cell) => in_array($cell->status, [...Status::NEEDS_PERSON, 'missing'], true))) {
                $file['needsWork']++;
            }

            $files[$row->namespace.'::'.$row->group] = $file;
        }

        return array_values($files);
    }

    public function key(Viewer $viewer, int $keyId): ?KeyDetail {
        $keyModel = Settings::model('key');
        /** @var TranslationKey|null $key */
        $key = $keyModel::query()->with('file')->whereKey($keyId)->whereNull('obsolete_at')->first();

        if ($key === null) {
            return null;
        }

        $row = $this->row($key, $viewer->locales(), $this->translationsFor($viewer->locales(), collect([$key])), $this->failures->all(), $this->rejections->all());
        $history = [];

        foreach ($viewer->locales() as $locale) {
            $translationId = $row->cells[$locale]->translationId;
            $reviewModel = Settings::model('review');
            $history[$locale] = $translationId === null ? [] : $reviewModel::query()->where('translation_id', $translationId)->orderBy('id')->get()
                ->map(fn (TranslationReview $review) => [
                    'action' => $review->action->value, 'reviewer' => $review->reviewer_id, 'previous' => $review->previous_value,
                    'new' => $review->new_value, 'notes' => $review->notes, 'at' => $review->created_at?->toIso8601String() ?? '',
                ])->values()->all();
        }

        return new KeyDetail($row, $history);
    }

    /**
     * @param array{namespace?: ?string, group?: ?string, locale?: ?string, status?: ?string, search?: ?string} $filters
     * @return list<KeyRow>
     */
    private function rows(Viewer $viewer, array $filters): array {
        $locales = array_values(array_filter($viewer->locales(), fn (string $code) => ($filters['locale'] ?? null) === null || $code === $filters['locale']));
        $keyModel = Settings::model('key');
        $keys = $keyModel::query()->with('file')->whereNull('obsolete_at')->orderBy('id')->get()
            ->filter(fn (TranslationKey $key) => (($filters['namespace'] ?? null) === null || $key->file->namespace === $filters['namespace'])
                && (($filters['group'] ?? null) === null || $key->file->group === $filters['group']))
            ->sortBy(fn (TranslationKey $key) => [$key->file->namespace, $key->file->group, $key->getKey()])
            ->values();
        $translations = $this->translationsFor($locales, $keys);
        $failures = $this->failures->all();
        $rejections = $this->rejections->all();
        $search = mb_strtolower((string) ($filters['search'] ?? ''));
        $rows = [];

        foreach ($keys as $key) {
            $row = $this->row($key, $locales, $translations, $failures, $rejections);

            if (($filters['status'] ?? null) !== null && ! collect($row->cells)->contains(fn (KeyCell $cell) => $cell->status === $filters['status'])) {
                continue;
            }

            if ($search !== '') {
                $text = mb_strtolower(implode("\n", [$row->keyRef, $row->source, ...array_map(fn (KeyCell $cell) => (string) $cell->value, $row->cells)]));

                if (! str_contains($text, $search)) {
                    continue;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<string> $locales
     * @param array<string, Collection<int, Translation>> $translations locale => translations keyed by key id
     * @param array<string, array<string, array{hash: string, count: int}>> $failures
     * @param array<string, array<string, array{hash: string, count: int}>> $rejections
     */
    private function row(TranslationKey $key, array $locales, array $translations, array $failures, array $rejections): KeyRow {
        $cells = [];

        foreach ($locales as $locale) {
            $translation = $translations[$locale]->get($key->getKey());
            $cells[$locale] = KeyCell::from($translation, Status::of($key, $translation, $locale, $failures, $rejections));
        }

        return KeyRow::from($key, $cells);
    }

    /**
     * @param list<string> $locales
     * @param \Illuminate\Support\Collection<int, TranslationKey> $keys
     * @return array<string, Collection<int, Translation>>
     */
    private function translationsFor(array $locales, \Illuminate\Support\Collection $keys): array {
        $model = Settings::model('translation');
        $ids = $keys->map(fn (TranslationKey $key) => $key->getKey())->all();
        $byLocale = [];

        foreach ($locales as $locale) {
            $byLocale[$locale] = $model::query()->where('locale', $locale)->whereIn('key_id', $ids)->get()->keyBy('key_id');
        }

        return $byLocale;
    }
}
```

- [ ] **Step 4: Run the tests and watch them pass, then run the suite**

First confirm the root namespace name the fixture uses:
Run: `grep -n "ROOT" src/Support/KeyRef.php`
Expected: `ROOT = '*'`. If it's different, use that value in the `files` test.

Run: `vendor/bin/pest tests/Feature/Review/KeyBrowserTest.php`
Expected: PASS, 5 tests.

Run: `vendor/bin/pest`
Expected: PASS, 403 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Review/KeyCell.php src/Review/KeyRow.php src/Review/KeyDetail.php src/Queries/KeyBrowser.php tests/Feature/Review/KeyBrowserTest.php
git commit -m "Add a keys browser: every key with a status cell per language, filters, search, files and history"
```

---

### Task 6: Coverage, shared health checks, and the last cycle report

**Files:**
- Create: `src/Automation/Health.php`, `src/Queries/Coverage.php`, `src/Queries/CoverageReport.php`
- Modify: `src/Console/HealthCommand.php`, `src/Automation/Cycle.php`, `src/Resilience/UsageLedger.php`
- Test: `tests/Feature/Review/CoverageTest.php`

**Interfaces:**
- Consumes: `Viewer`, `Status::of`, `CycleFailures`, `Rejections`, `Budget::usage()`, `Circuits`, `State`, `LocaleSource`.
- Produces:
  - `Health::problems(): list<string>` (the same lines `prosetta:health` prints)
  - `UsageLedger::sum(?string $runId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $locale = null): int`
  - State key `cycle.last_report`: `CycleReport::toArray()` plus `at` (a Unix time)
  - `Coverage::for(Viewer $viewer): CoverageReport`
  - `CoverageReport` fields:
    - `list<array{code: string, name: string, nativeName: string, mode: string, keys: int, approved: int, draft: int, flagged: int, pending: int, stale: int, missing: int, held: int, tokensThisMonth: int}> languages`
    - `?int lastCycleAt`, `?array lastReport`
    - `list<array{name: string, state: string, reason: ?string, until: ?int}> circuits`
    - `array budget` (from `Budget::usage()`)
    - `list<string> problems`
    - `bool editable`
    - `toArray()`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Review/CoverageTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Queries\Coverage;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
});

function coverageViewer(array $translate): Viewer {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability !== Ability::Manage && in_array($locale, $translate, true));

    return Viewer::for(new GenericUser(['id' => 'u1']));
}

it('counts only the viewer\'s languages, by the same rules as the queue', function () {
    $report = app(Coverage::class)->for(coverageViewer(['es']));
    $es = $report->languages[0];

    expect(collect($report->languages)->pluck('code')->all())->toBe(['es'])
        ->and($es['keys'])->toBe($es['approved'] + $es['draft'] + $es['flagged'] + $es['pending'] + $es['stale'] + $es['missing'] + $es['held'])
        ->and($es['approved'])->toBeGreaterThan(0)
        ->and($es['mode'])->toBe('ai');
});

it('reports a derived language, this month\'s tokens per language, and the last cycle', function () {
    \LonelyLights\Prosetta\Models\Locale::query()->where('locale_initials', 'en_GB')->update(['replacements' => [['from' => 'color', 'to' => 'colour']]]);
    app(UsageLedger::class)->record(null, 'c', 'es', 100, 50);
    app()->instance(TranslationDriver::class, new ScriptedDriver);
    app(Cycle::class)->run(sync: true);

    $report = app(Coverage::class)->for(coverageViewer(['es', 'en_GB']));

    expect(collect($report->languages)->firstWhere('code', 'en_GB')['mode'])->toBe('derived')
        ->and(collect($report->languages)->firstWhere('code', 'es')['tokensThisMonth'])->toBeGreaterThanOrEqual(150)
        ->and($report->lastCycleAt)->not->toBeNull()
        ->and($report->lastReport)->toHaveKeys(['drafted', 'flagged', 'at'])
        ->and(State::get('cycle.last_report'))->toBeArray()
        ->and($report->toArray())->toHaveKeys(['languages', 'circuits', 'budget', 'problems', 'editable']);
});

it('shares its health problems with prosetta:health', function () {
    config(['prosetta.automation.every' => 30]);

    $problems = app(Coverage::class)->for(coverageViewer(['es']))->problems;

    expect($problems)->toBe(['[cycle_stale] automation is on but no cycle has run yet']);
    $this->artisan('prosetta:health')->expectsOutput('[cycle_stale] automation is on but no cycle has run yet')->assertFailed();
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/pest tests/Feature/Review/CoverageTest.php`
Expected: FAIL, with `Class "LonelyLights\Prosetta\Queries\Coverage" not found`.

- [ ] **Step 3: Implement**

`src/Automation/Health.php`. Move the three checks out of `HealthCommand::handle` unchanged:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Automation;

use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Support\State;

/**
 * What's wrong with the automation right now, as the lines prosetta:health
 * prints: each starts with a stable code in brackets ([cycle_stale],
 * [circuit_halted:{name}], [budget:{period}]) and then the human text.
 */
final readonly class Health {
    public function __construct(private Circuits $circuits, private Budget $budget) {}

    /** @return list<string> */
    public function problems(): array {
        $problems = [];
        $every = config('prosetta.automation.every');

        # Automation Is On and the Last Cycle Is Missing or Old
        if ($every !== null && (int) $every > 0) {
            $lastRun = State::get('cycle.last_run');
            $maxMinutes = (int) $every * 3;

            if ($lastRun === null) {
                $problems[] = '[cycle_stale] automation is on but no cycle has run yet';
            } elseif (($age = now()->getTimestamp() - (int) $lastRun) > $maxMinutes * 60) {
                $problems[] = '[cycle_stale] last cycle was '.floor($age / 60)." minutes ago (max: $maxMinutes)";
            }
        }

        # A Halted Circuit
        foreach ($this->circuits->names() as $name) {
            $state = $this->circuits->for($name)->state();

            if ($state['state'] === 'open' && $state['reason'] === 'halt') {
                $problems[] = "[circuit_halted:$name] circuit [$name]: halted ({$state['halt']})";
            }
        }

        # The Daily or Monthly Budget Is Spent
        if (($exhausted = $this->budget->exhausted(null)) !== null) {
            $problems[] = "[budget:$exhausted] $exhausted budget: exhausted";
        }

        return $problems;
    }
}
```

Replace `HealthCommand::handle` with:

```php
    public function handle(Health $health): int {
        $problems = $health->problems();

        if ($problems === []) {
            $this->line('healthy');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->line($problem);
        }

        return self::FAILURE;
    }
```

Keep its class docblock about the codes, swap the `Budget`, `Circuits` and `State` imports for `use LonelyLights\Prosetta\Automation\Health;`, and keep the existing HealthCommand tests passing unchanged.

In `src/Resilience/UsageLedger.php`, add a trailing `?string $locale = null` parameter to `sum`, and apply it to the query with `->when($locale !== null, fn ($query) => $query->where('locale', $locale))`, next to the existing run and date filters.

In `src/Automation/Cycle.php`, right after `State::put('cycle.last_run', now()->getTimestamp());`, add:

```php
        State::put('cycle.last_report', [...$report->toArray(), 'at' => now()->getTimestamp()]);
```

`src/Queries/CoverageReport.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

/** Coverage per language and the automation's health, for an overview page. */
final readonly class CoverageReport {
    /**
     * @param list<array{code: string, name: string, nativeName: string, mode: string, keys: int, approved: int, draft: int, flagged: int, pending: int, stale: int, missing: int, held: int, tokensThisMonth: int}> $languages
     * @param array<string, mixed>|null $lastReport
     * @param list<array{name: string, state: string, reason: ?string, until: ?int}> $circuits
     * @param array<string, array{used: int, limit: ?int}> $budget
     * @param list<string> $problems
     */
    public function __construct(
        public array $languages,
        public ?int $lastCycleAt,
        public ?array $lastReport,
        public array $circuits,
        public array $budget,
        public array $problems,
        public bool $editable,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
```

`src/Queries/Coverage.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Queries;

use LonelyLights\Prosetta\Automation\CycleFailures;
use LonelyLights\Prosetta\Automation\Health;
use LonelyLights\Prosetta\Automation\Rejections;
use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Resilience\Budget;
use LonelyLights\Prosetta\Resilience\Circuits;
use LonelyLights\Prosetta\Resilience\UsageLedger;
use LonelyLights\Prosetta\Review\Status;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Support\Settings;
use LonelyLights\Prosetta\Support\State;

/** How far each of the viewer's languages is, and how the automation is doing. */
final readonly class Coverage {
    public function __construct(
        private LocaleSource $locales,
        private CycleFailures $failures,
        private Rejections $rejections,
        private UsageLedger $usage,
        private Budget $budget,
        private Circuits $circuits,
        private Health $health,
    ) {}

    public function for(Viewer $viewer): CoverageReport {
        $keyModel = Settings::model('key');
        $translationModel = Settings::model('translation');
        $keys = $keyModel::query()->whereNull('obsolete_at')->get();
        $failures = $this->failures->all();
        $rejections = $this->rejections->all();
        $languages = [];

        foreach ($viewer->locales() as $code) {
            $descriptor = $this->locales->find($code);
            $translations = $translationModel::query()->where('locale', $code)->get()->keyBy('key_id');
            $counts = array_fill_keys(['approved', 'draft', 'flagged', 'pending', 'stale', 'missing', 'held'], 0);

            foreach ($keys as $key) {
                /** @var TranslationKey $key */
                $counts[Status::of($key, $translations->get($key->getKey()), $code, $failures, $rejections)]++;
            }

            $languages[] = [
                'code' => $code,
                'name' => $descriptor?->englishName ?? $code,
                'nativeName' => $descriptor?->nativeName ?? $code,
                'mode' => ($descriptor?->replacements ?? []) !== [] ? 'derived' : 'ai',
                'keys' => $keys->count(),
                ...$counts,
                'tokensThisMonth' => $this->usage->sum(from: now()->startOfMonth(), locale: $code),
            ];
        }

        $lastRun = State::get('cycle.last_run');
        $lastReport = State::get('cycle.last_report');

        return new CoverageReport(
            $languages,
            $lastRun === null ? null : (int) $lastRun,
            is_array($lastReport) ? $lastReport : null,
            array_map(function (string $name) {
                $state = $this->circuits->for($name)->state();

                return ['name' => $name, 'state' => (string) $state['state'], 'reason' => $state['reason'], 'until' => $state['until']];
            }, $this->circuits->names()),
            $this->budget->usage(),
            $this->health->problems(),
            $viewer->isEditable,
        );
    }
}
```

- [ ] **Step 4: Run the tests and watch them pass, then run the suite**

Run: `vendor/bin/pest tests/Feature/Review/CoverageTest.php tests/Feature/Console/HealthCommandTest.php`
Expected: PASS, the 3 new tests and every existing HealthCommand test.

Run: `vendor/bin/pest`
Expected: PASS, 406 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Automation/Health.php src/Queries/Coverage.php src/Queries/CoverageReport.php src/Console/HealthCommand.php src/Automation/Cycle.php src/Resilience/UsageLedger.php tests/Feature/Review/CoverageTest.php
git commit -m "Add coverage per language with the automation's health, sharing prosetta:health's checks and keeping the last cycle report"
```

---

### Task 7: The review desk (batches, re-draft, queued cycle)

**Files:**
- Create: `src/Review/BatchReport.php`, `src/Review/ReviewDesk.php`
- Test: `tests/Feature/Review/ReviewDeskTest.php`

**Interfaces:**
- Consumes: `ReviewQueue::all` (Task 4), `ReviewService::approve`/`reject` with fingerprints (Task 2), `Viewer` (Task 1), `Estimator::estimate`, `ProsettaManager::translate`, `Cycle::run`, `Authorizer`.
- Produces:
  - `ReviewDesk::approveMatching(Viewer $viewer, array $filters, bool $includeWarnings = false): BatchReport`
  - `ReviewDesk::approveMany(Viewer $viewer, array $expected): BatchReport`, where `$expected` maps a translation id to its fingerprint
  - `ReviewDesk::rejectMany(Viewer $viewer, array $expected, string $note): BatchReport`
  - `ReviewDesk::estimateRedraft(array $refsByLocale): array` (the `Estimator` shape per locale)
  - `ReviewDesk::redraft(Viewer $viewer, array $refsByLocale): void`
  - `ReviewDesk::runCycle(Viewer $viewer): void`
  - `BatchReport`: `int approved`, `int rejected`, `int skippedWarnings`, `int skippedErrors`, `int conflicts`, and `array<int, string> skipped` (id to reason), plus `toArray()`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Review/ReviewDeskTest.php`:

```php
<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Bus;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewDesk;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    config(['prosetta.resilience.cache_store' => 'array', 'prosetta.resilience.jitter' => 0]);
    app(Syncer::class)->sync();
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability === Ability::Manage || $locale === 'es');
    $this->viewer = Viewer::for(new GenericUser(['id' => 'u1']));
});

function deskDraft(string $ref, string $value, ?array $issues = null, string $locale = 'es'): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->updateOrCreate(['key_id' => $key->id, 'locale' => $locale], [
        'value' => $value, 'source_hash' => $key->source_hash, 'status' => TranslationStatus::Draft, 'origin' => 'ai', 'issues' => $issues,
    ]);
}

it('approves only clean items unless warnings are included, and says what it skipped', function () {
    $clean = deskDraft('auth.throttle', 'Demasiados intentos. Espera :seconds segundos.');
    $warned = deskDraft('messages.terms', 'Lee los <a href=":url">términos</a>.', [['code' => 'glossary_missing', 'severity' => 'warning', 'message' => 'x']]);
    $broken = deskDraft('messages.welcome', '¡Hola!', [['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'x']]);

    $report = app(ReviewDesk::class)->approveMatching($this->viewer, ['locale' => 'es']);

    expect($report->approved)->toBe(1)
        ->and($report->skippedWarnings)->toBe(1)
        ->and($report->skippedErrors)->toBe(1)
        ->and($clean->refresh()->status)->toBe(TranslationStatus::Approved)
        ->and($warned->refresh()->status)->toBe(TranslationStatus::Draft)
        ->and($broken->refresh()->status)->toBe(TranslationStatus::Draft);

    expect(app(ReviewDesk::class)->approveMatching($this->viewer, ['locale' => 'es'], includeWarnings: true)->approved)->toBe(1)
        ->and($warned->refresh()->status)->toBe(TranslationStatus::Approved);
});

it('never touches a language the viewer can\'t review', function () {
    $arabic = deskDraft('auth.throttle', 'محاولات كثيرة. انتظر :seconds ثانية.', locale: 'ar');

    app(ReviewDesk::class)->approveMatching($this->viewer, []);

    expect($arabic->refresh()->status)->toBe(TranslationStatus::Draft);
});

it('skips and counts conflicted items in a batch', function () {
    $one = deskDraft('auth.throttle', 'Uno :seconds');
    $two = deskDraft('messages.welcome', 'Hola, :name');

    $report = app(ReviewDesk::class)->approveMany($this->viewer, [$one->id => 'stale', $two->id => ReviewService::fingerprint($two)]);

    expect($report->approved)->toBe(1)->and($report->conflicts)->toBe(1);
});

it('rejects many with one note', function () {
    $one = deskDraft('auth.throttle', 'Uno :seconds');
    $two = deskDraft('messages.welcome', 'Hola, :name');

    $report = app(ReviewDesk::class)->rejectMany($this->viewer, [$one->id => ReviewService::fingerprint($one), $two->id => ReviewService::fingerprint($two)], 'Too formal.');

    expect($report->rejected)->toBe(2)
        ->and($one->refresh()->status)->toBe(TranslationStatus::Rejected)
        ->and($one->reviews()->latest('id')->first()->notes)->toBe('Too formal.');
});

it('estimates and queues a re-draft of chosen keys, and queues a cycle', function () {
    Bus::fake();

    $estimate = app(ReviewDesk::class)->estimateRedraft(['es' => ['auth.failed']]);
    app(ReviewDesk::class)->redraft($this->viewer, ['es' => ['auth.failed']]);
    app(ReviewDesk::class)->runCycle($this->viewer);

    expect($estimate['es']['strings'])->toBe(1);
    Bus::assertBatchCount(2);
});

it('refuses batches, re-drafts and cycles where review is read-only', function () {
    config(['prosetta.review.editable' => false]);
    $viewer = Viewer::for(new GenericUser(['id' => 'u1']));

    expect(fn () => app(ReviewDesk::class)->approveMatching($viewer, []))->toThrow(ReviewLocked::class)
        ->and(fn () => app(ReviewDesk::class)->runCycle($viewer))->toThrow(ReviewLocked::class);
});
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/pest tests/Feature/Review/ReviewDeskTest.php`
Expected: FAIL, with `Class "LonelyLights\Prosetta\Review\ReviewDesk" not found`.

- [ ] **Step 3: Implement**

`src/Review/BatchReport.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

/** What a batch action did, so a page can say "312 approved; 9 with warnings and 3 with errors left for you". */
final class BatchReport {
    public int $approved = 0;

    public int $rejected = 0;

    public int $skippedWarnings = 0;

    public int $skippedErrors = 0;

    public int $conflicts = 0;

    /** @var array<int, string> translation id => reason */
    public array $skipped = [];

    /** @return array<string, mixed> */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
```

`src/Review/ReviewDesk.php`:

```php
<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Review;

use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Automation\Cycle;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Exceptions\ReviewConflict;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\ProsettaManager;
use LonelyLights\Prosetta\Translation\Estimator;
use Throwable;

/**
 * The review screens' bigger actions: approving or rejecting many items at
 * once, asking the AI again, and queuing a cycle. Every one checks the
 * viewer's permissions and the editable guard.
 */
final readonly class ReviewDesk {
    private const int CHUNK = 200;

    public function __construct(
        private ReviewService $service,
        private ReviewQueue $queue,
        private Estimator $estimator,
        private ProsettaManager $prosetta,
        private Cycle $cycle,
        private Authorizer $authorizer,
    ) {}

    /**
     * Approves every item in the queue matching the filter that the viewer
     * may review: never one with errors, and one with warnings only when asked.
     *
     * @param array{locale?: ?string, reason?: ?string, namespace?: ?string, group?: ?string, search?: ?string} $filters
     * @throws Throwable when a database transaction fails
     */
    public function approveMatching(Viewer $viewer, array $filters, bool $includeWarnings = false): BatchReport {
        $this->unlocked($viewer);
        $report = new BatchReport;
        $expected = [];

        foreach ($this->queue->all($viewer, $filters) as $item) {
            if ($item->translationId === null || $item->candidate === null || ! in_array($item->reason, ['draft', 'flagged', 'pending'], true) || ! $viewer->canReview($item->locale)) {
                continue;
            }

            if ($item->blocking) {
                $report->skippedErrors++;

                continue;
            }

            if ($item->warnings && ! $includeWarnings) {
                $report->skippedWarnings++;

                continue;
            }

            $expected[$item->translationId] = (string) $item->fingerprint;
        }

        return $this->approveChunks($viewer, $expected, $report);
    }

    /**
     * @param array<int, string> $expected translation id => the fingerprint the page saw
     * @throws Throwable when a database transaction fails
     */
    public function approveMany(Viewer $viewer, array $expected): BatchReport {
        $this->unlocked($viewer);

        return $this->approveChunks($viewer, $expected, new BatchReport);
    }

    /**
     * @param array<int, string> $expected translation id => the fingerprint the page saw
     * @throws Throwable when a database transaction fails
     */
    public function rejectMany(Viewer $viewer, array $expected, string $note): BatchReport {
        $this->unlocked($viewer);
        $report = new BatchReport;

        foreach ($expected as $id => $fingerprint) {
            try {
                $this->service->reject((int) $id, $viewer->user, $note, expected: $fingerprint);
                $report->rejected++;
            } catch (ReviewConflict) {
                $report->conflicts++;
                $report->skipped[(int) $id] = 'conflict';
            }
        }

        return $report;
    }

    /**
     * @param array<string, list<string>> $refsByLocale locale => key refs
     * @return array<string, array{strings: int, chars: int, input: int, output: int, from_history: bool}>
     */
    public function estimateRedraft(array $refsByLocale): array {
        $estimates = [];

        foreach ($refsByLocale as $locale => $refs) {
            $estimates += $this->estimator->estimate([$locale], [], $refs, force: true);
        }

        return $estimates;
    }

    /**
     * Queues the chosen keys for a fresh AI draft; budgets and the circuit breaker apply as usual.
     *
     * @param array<string, list<string>> $refsByLocale locale => key refs
     * @throws Throwable when a batch cannot be dispatched
     */
    public function redraft(Viewer $viewer, array $refsByLocale): void {
        $this->unlocked($viewer);

        foreach ($refsByLocale as $locale => $refs) {
            $this->prosetta->translate([$locale], [], $refs, force: true, queue: true, by: $viewer->user);
        }
    }

    /** @throws Throwable when the cycle's batch cannot be dispatched */
    public function runCycle(Viewer $viewer): void {
        $this->unlocked($viewer);
        $this->authorizer->authorize($viewer->user, Ability::Manage);
        $this->cycle->run();
    }

    /**
     * @param array<int, string> $expected
     * @throws Throwable when a database transaction fails
     */
    private function approveChunks(Viewer $viewer, array $expected, BatchReport $report): BatchReport {
        foreach (array_chunk($expected, self::CHUNK, preserve_keys: true) as $chunk) {
            $result = $this->service->approve(array_keys($chunk), $viewer->user, expected: $chunk);
            $report->approved += count($result->approved);

            foreach ($result->skipped as $id => $reason) {
                $report->skipped[(int) $id] = $reason;

                if ($reason === 'conflict') {
                    $report->conflicts++;
                }
            }
        }

        return $report;
    }

    private function unlocked(Viewer $viewer): void {
        if ($viewer->user !== null && ! Viewer::editable()) {
            throw ReviewLocked::make();
        }
    }
}
```

If `Cycle::run()`'s parameter isn't `bool $sync = false`, match its real signature. It runs queued by default.

- [ ] **Step 4: Run the tests and watch them pass, then run the suite**

Run: `vendor/bin/pest tests/Feature/Review/ReviewDeskTest.php`
Expected: PASS, 6 tests. If `Bus::assertBatchCount(2)` fails because the cycle found no work, that's correct behavior. In that case, change the assertion to expect one batch for the re-draft, and add a separate assertion that `State::get('cycle.last_run')` is set after `runCycle`.

Run: `vendor/bin/pest`
Expected: PASS, 412 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Review/BatchReport.php src/Review/ReviewDesk.php tests/Feature/Review/ReviewDeskTest.php
git commit -m "Add the review desk: approve everything clean, batch approve and reject with conflict checks, re-draft, and a queued cycle"
```

---

### Task 8: README and the finished branch

**Files:**
- Modify: `README.md`
- Test: the full suite

- [ ] **Step 1: Document the review core**

Add a `## Review core (for review UIs)` section to `README.md`, after the Background mode section. It should cover:
- `Viewer::for($user)` and the editable flag (`prosetta.review.editable` and `PROSETTA_REVIEW_EDITABLE`, defaulting to local and staging only; people only, never the cycle);
- `ReviewQueue::for/count/all` with its reasons (`draft`, `flagged`, `pending`, `stale`, `held`) and filters;
- `KeyBrowser::for/matchCount/files/key` and the 500-result search cap;
- `Coverage::for`;
- `ReviewDesk` (approve matching, approve or reject many, re-draft, run cycle), with fingerprints from `ReviewService::fingerprint()` and the `ReviewConflict` and `ReviewLocked` exceptions;
- rejection notes that feed the next draft, and the two-rejection hold (`review.rejections` in `prosetta_state`);
- that every result has a `toArray()` for Inertia props or JSON.

Keep it to the facts above, with one short example of a controller calling `ReviewQueue::for(Viewer::for($request->user()), $request->only([...]))`.

- [ ] **Step 2: Run the full suite**

Run: `vendor/bin/pest`
Expected: PASS, 412 tests.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "Document the review core"
```
