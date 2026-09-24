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

it('gives a manager every target language to translate and review', function () {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability === Ability::Manage);
    $targets = array_map(fn ($locale) => $locale->code, app(\LonelyLights\Prosetta\Contracts\LocaleSource::class)->targets());

    $viewer = Viewer::for(new GenericUser(['id' => 'u1']));

    expect($targets)->not->toBeEmpty()
        ->and($viewer->manages)->toBeTrue()
        ->and($viewer->translates)->toBe($targets)
        ->and($viewer->reviews)->toBe($targets);
});
