<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\Ability;

it('denies outside local when no hook is registered', function () {
    expect(app(Authorizer::class)->allows($this->user(), Ability::Review, 'es'))->toBeFalse();
});

it('allows everything locally when no hook is registered', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(app(Authorizer::class)->allows($this->user(), Ability::Manage))->toBeTrue();
});

it('always allows the system (no user)', function () {
    expect(app(Authorizer::class)->allows(null, Ability::Manage))->toBeTrue();
});

it('asks the host hook with the ability and the locale', function () {
    app(Authorizer::class)->using(fn (Authenticatable $user, Ability $ability, ?string $locale) => $ability === Ability::Review && $locale === 'ar');

    expect(app(Authorizer::class)->allows($this->user(), Ability::Review, 'ar'))->toBeTrue()
        ->and(app(Authorizer::class)->allows($this->user(), Ability::Review, 'es'))->toBeFalse()
        ->and(app(Authorizer::class)->allows($this->user(), Ability::Manage))->toBeFalse();
});

it('lets a reviewer translate the locales they review', function () {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $ability === Ability::Review && $locale === 'ar');

    expect(app(Authorizer::class)->allows($this->user(), Ability::Translate, 'ar'))->toBeTrue()
        ->and(app(Authorizer::class)->allows($this->user(), Ability::Translate, 'es'))->toBeFalse();
});

it('registers Laravel gates that take the locale', function () {
    app(Authorizer::class)->using(fn ($user, Ability $ability, ?string $locale) => $locale === 'es');

    expect(Gate::forUser($this->user())->allows('prosetta.review', 'es'))->toBeTrue()
        ->and(Gate::forUser($this->user())->allows('prosetta.review', 'ar'))->toBeFalse();
});

it('throws a standard authorization exception', function () {
    expect(fn () => app(Authorizer::class)->authorize($this->user(), Ability::Review, 'es'))
        ->toThrow(AuthorizationException::class, 'review es');
});
