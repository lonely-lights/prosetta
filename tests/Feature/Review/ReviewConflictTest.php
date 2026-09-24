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
