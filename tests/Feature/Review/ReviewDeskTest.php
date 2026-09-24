<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Bus;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewDesk;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Support\State;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;

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
    app()->instance(TranslationDriver::class, new ScriptedDriver);

    $estimate = app(ReviewDesk::class)->estimateRedraft(['es' => ['auth.failed']]);
    app(ReviewDesk::class)->redraft($this->viewer, ['es' => ['auth.failed']]);
    app(ReviewDesk::class)->runCycle($this->viewer);

    expect($estimate['es']['strings'])->toBe(1);
    # The Cycle Found No Work of Its Own, so Only the Re-Draft's Batch Was Dispatched
    Bus::assertBatchCount(1);
    expect(State::get('cycle.last_run'))->not->toBeNull();
});

it('refuses batches, re-drafts and cycles where review is read-only', function () {
    config(['prosetta.review.editable' => false]);
    $viewer = Viewer::for(new GenericUser(['id' => 'u1']));

    expect(fn () => app(ReviewDesk::class)->approveMatching($viewer, []))->toThrow(ReviewLocked::class)
        ->and(fn () => app(ReviewDesk::class)->runCycle($viewer))->toThrow(ReviewLocked::class);
});
