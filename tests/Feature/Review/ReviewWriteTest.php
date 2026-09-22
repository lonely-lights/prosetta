<?php

use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Facades\Prosetta;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\MissingItem;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $locale === 'es' || $ability === Ability::Manage);
});

function aiDraft(string $ref, string $value): Translation {
    $key = app(KeyFinder::class)->find($ref);

    return Translation::query()->create([
        'key_id' => $key->id, 'locale' => 'es', 'value' => $value, 'source_hash' => $key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai,
    ]);
}

it('changes nothing when edit-and-approve is refused for blocking issues', function () {
    $draft = aiDraft('identity::onboarding.toast.accessCode.capReached', 'Límite de :minutes minutos.');

    expect(fn () => app(ReviewService::class)->edit($draft->id, 'Límite sin marcador.', $this->user(), approve: true))
        ->toThrow(ProsettaException::class, 'issues');

    $draft->refresh();

    expect($draft->value)->toBe('Límite de :minutes minutos.')
        ->and($draft->status)->toBe(TranslationStatus::Draft)
        ->and($draft->reviews()->count())->toBe(0);
});

it('changes nothing when edit-and-approve would be a self-approval that config forbids', function () {
    config()->set('prosetta.review.allow_self_approval', false);
    $draft = aiDraft('identity::onboarding.toast.accessCode.timedOut', 'x');

    expect(fn () => app(ReviewService::class)->edit($draft->id, 'Tu registro estuvo en pausa.', $this->user('ana'), approve: true))
        ->toThrow(ProsettaException::class, 'self_approval');

    expect($draft->fresh()->value)->toBe('x');
});

it('translates a missing key by hand through the review trail', function () {
    $written = app(ReviewService::class)->write('identity::onboarding.toast.accessCode.timedOut', 'es', 'Tu registro estuvo en pausa.', $this->user('ana'), 'By hand');

    expect($written->status)->toBe(TranslationStatus::NeedsReview)
        ->and($written->origin)->toBe(TranslationOrigin::Manual)
        ->and($written->value)->toBe('Tu registro estuvo en pausa.')
        ->and($written->reviews()->first()->action)->toBe(ReviewAction::Edited)
        ->and($written->reviews()->first()->reviewer_id)->toBe('ana');
});

it('can write and approve a missing key in one step', function () {
    $written = app(ReviewService::class)->write('identity::onboarding.toast.accessCode.timedOut', 'es', 'Tu registro estuvo en pausa.', $this->user(), approve: true);

    expect($written->status)->toBe(TranslationStatus::Approved)
        ->and($written->approved_value)->toBe('Tu registro estuvo en pausa.');
});

it('refuses to write unknown keys, the source locale and locales Prosetta does not maintain', function (string $ref, string $locale) {
    expect(fn () => app(ReviewService::class)->write($ref, $locale, 'x', null))->toThrow(ProsettaException::class);
})->with([
    ['identity::onboarding.toast.nope', 'es'],
    ['auth.failed', 'en'],
    ['auth.failed', 'fr'],
]);

it('lists missing keys for a locale', function () {
    $page = app(ReviewQueue::class)->missing('es', ['namespace' => 'identity']);

    expect($page->total())->toBe(2)
        ->and($page->items()[0])->toBeInstanceOf(MissingItem::class)
        ->and(collect($page->items())->pluck('keyRef')->all())->toBe([
            'identity::onboarding.toast.accessCode.capReached',
            'identity::onboarding.toast.accessCode.timedOut',
        ])
        ->and($page->items()[0]->placeholders)->toBe([':minutes']);

    app(ReviewService::class)->write('identity::onboarding.toast.accessCode.timedOut', 'es', 'Tu registro estuvo en pausa.', null);

    expect(app(ReviewQueue::class)->missing('es', ['namespace' => 'identity'])->total())->toBe(1);
});

it('offers both through the facade', function () {
    expect(Prosetta::missing('es')->total())->toBe(8 + 2);

    Prosetta::write('auth.throttle', 'es', 'Demasiados intentos. Inténtalo de nuevo en :seconds segundos.', null);

    expect(Prosetta::missing('es')->total())->toBe(9);
});
