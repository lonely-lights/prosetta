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
