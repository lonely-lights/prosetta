<?php

use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewItem;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();

    $key = app(KeyFinder::class)->find('identity::onboarding.toast.accessCode.capReached');
    Translation::query()->create([
        'key_id' => $key->id, 'locale' => 'es', 'value' => 'Límite de :minutes minutos.', 'source_hash' => $key->source_hash,
        'status' => TranslationStatus::Draft, 'origin' => TranslationOrigin::Ai, 'ai_model' => 'gpt-test', 'ai_provider' => 'openai',
    ]);
});

it('lists drafts and needs-review items for one locale', function () {
    $page = app(ReviewQueue::class)->forLocale('es');
    $item = $page->items()[0];

    expect($page->total())->toBe(1)
        ->and($item)->toBeInstanceOf(ReviewItem::class)
        ->and($item->keyRef)->toBe('identity::onboarding.toast.accessCode.capReached')
        ->and($item->source)->toStartWith('Registration has a :minutes-minute limit')
        ->and($item->candidate)->toBe('Límite de :minutes minutos.')
        ->and($item->status)->toBe('draft')
        ->and($item->aiModel)->toBe('gpt-test')
        ->and($item->stale)->toBeFalse()
        ->and(app(ReviewQueue::class)->forLocale('ar')->total())->toBe(0);
});

it('includes approved-but-stale items when asked', function () {
    $path = $this->fixture.'/lang/en/auth.php';
    file_put_contents($path, str_replace('do not match our records', 'are wrong', file_get_contents($path)));
    app(Syncer::class)->sync();

    $page = app(ReviewQueue::class)->forLocale('es', ['stale' => true]);

    expect(collect($page->items())->pluck('keyRef')->all())->toContain('auth.failed')
        ->and(collect($page->items())->firstWhere('keyRef', 'auth.failed')->stale)->toBeTrue();
});

it('filters by namespace, origin and search', function () {
    expect(app(ReviewQueue::class)->forLocale('es', ['namespace' => '*'])->total())->toBe(0)
        ->and(app(ReviewQueue::class)->forLocale('es', ['origin' => 'ai'])->total())->toBe(1)
        ->and(app(ReviewQueue::class)->forLocale('es', ['search' => 'Límite'])->total())->toBe(1)
        ->and(app(ReviewQueue::class)->forLocale('es', ['search' => 'nothing like this'])->total())->toBe(0);
});

it('searches case-insensitively and treats % and _ as literal characters', function () {
    expect(app(ReviewQueue::class)->forLocale('es', ['search' => 'REGISTRATION'])->total())->toBe(1)
        ->and(app(ReviewQueue::class)->forLocale('es', ['search' => '%'])->total())->toBe(0)
        ->and(app(ReviewQueue::class)->missing('es', ['search' => 'LOGIN ATTEMPTS'])->total())->toBe(1)
        ->and(app(ReviewQueue::class)->missing('es', ['search' => '_'])->total())->toBe(0);
});
