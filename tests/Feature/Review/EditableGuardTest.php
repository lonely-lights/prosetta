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
