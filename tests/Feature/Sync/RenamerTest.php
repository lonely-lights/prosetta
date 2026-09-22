<?php

use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Sync\Renamer;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function renameInUseInEnglish(string $fixture): void {
    $path = $fixture.'/modules/Identity/Lang/en/onboarding.php';
    file_put_contents($path, str_replace("'inUse' =>", "'inUseElsewhere' =>", file_get_contents($path)));
}

it('finds a key by reference', function () {
    $key = app(KeyFinder::class)->find('identity::onboarding.toast.accessCode.inUse');

    expect($key?->key)->toBe('toast.accessCode.inUse')
        ->and($key->translations)->toHaveCount(1)
        ->and(app(KeyFinder::class)->find('json:Save changes')?->source_value)->toBe('Save changes')
        ->and(app(KeyFinder::class)->find('auth.nope'))->toBeNull();
});

it('moves translations and history to the renamed key', function () {
    renameInUseInEnglish($this->fixture);
    app(Syncer::class)->sync();

    $key = app(Renamer::class)->rename('identity::onboarding.toast.accessCode.inUse', 'identity::onboarding.toast.accessCode.inUseElsewhere');
    $spanish = $key->translations->firstWhere('locale', 'es');

    expect($spanish->approved_value)->toStartWith('Este código de acceso')
        ->and(WorkState::isStale($key, $spanish))->toBeFalse()
        ->and(TranslationKey::query()->withKey('toast.accessCode.inUse')->exists())->toBeFalse();
});

it('refuses to rename onto a key that already has translations', function () {
    expect(fn () => app(Renamer::class)->rename('identity::onboarding.toast.accessCode.inUse', 'auth.failed'))
        ->toThrow(ProsettaException::class, 'already has translations');
});

it('refuses references that do not exist yet', function () {
    expect(fn () => app(Renamer::class)->rename('identity::onboarding.toast.accessCode.inUse', 'identity::onboarding.toast.nope'))
        ->toThrow(ProsettaException::class, 'Rename it in the source file');
});
