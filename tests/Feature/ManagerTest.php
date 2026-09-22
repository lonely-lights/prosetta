<?php

use Illuminate\Auth\Access\AuthorizationException;
use LonelyLights\Prosetta\Enums\Ability;
use LonelyLights\Prosetta\Facades\Prosetta;
use LonelyLights\Prosetta\Models\TranslationKey;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
});

it('checks without keeping anything', function () {
    $report = Prosetta::sync(check: true);

    expect($report->outstanding)->toBe(35)
        ->and($report->added)->toHaveCount(13)
        ->and(TranslationKey::query()->count())->toBe(0);
});

it('syncs for real through the facade', function () {
    Prosetta::sync();

    expect(TranslationKey::query()->count())->toBe(13)
        ->and(Prosetta::lookup('identity::onboarding.toast.accessCode.inUse')?->translations)->toHaveCount(1)
        ->and(Prosetta::stats('es')['es']['identity']['missing'])->toBe(2);
});

it('requires Manage to sync, export or rename as a user', function () {
    Prosetta::authorizeUsing(fn ($user, Ability $ability) => $ability !== Ability::Manage);

    expect(fn () => Prosetta::sync(by: $this->user()))->toThrow(AuthorizationException::class)
        ->and(fn () => Prosetta::export(by: $this->user()))->toThrow(AuthorizationException::class)
        ->and(fn () => Prosetta::rename('auth.failed', 'auth.throttle', $this->user()))->toThrow(AuthorizationException::class);
});

it('requires Translate for each locale it drafts as a user', function () {
    Prosetta::sync();
    Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale) => $locale === 'es');

    expect(fn () => Prosetta::translate(['ar'], by: $this->user()))->toThrow(AuthorizationException::class);
});
