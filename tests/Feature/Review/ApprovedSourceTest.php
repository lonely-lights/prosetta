<?php

use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Support\WorkState;
use LonelyLights\Prosetta\Sync\Syncer;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

function spanishFailed(): Translation {
    return Translation::query()->where('key_id', app(KeyFinder::class)->find('auth.failed')->id)->where('locale', 'es')->firstOrFail();
}

function editEnglishFailed(string $fixture, string $to): void {
    $path = $fixture.'/lang/en/auth.php';
    file_put_contents($path, str_replace("'These credentials do not match our records.'", var_export($to, true), file_get_contents($path)));
    app(Syncer::class)->sync();
}

it('records the English an imported translation was approved against', function () {
    expect(spanishFailed()->approved_source_value)->toBe('These credentials do not match our records.');
});

it('records it on approval through write, edit and approve', function () {
    $translation = app(ReviewService::class)->write('auth.throttle', 'es', 'Demasiados intentos. Inténtalo de nuevo en :seconds segundos.', null, approve: true);

    expect($translation->approved_source_value)->toBe('Too many login attempts. Please try again in :seconds seconds.');
});

it('each language keeps the English it was approved against', function () {
    $original = 'These credentials do not match our records.';
    app(ReviewService::class)->write('auth.failed', 'ar', 'بيانات الاعتماد هذه لا تطابق سجلاتنا.', null, approve: true);
    editEnglishFailed($this->fixture, 'These details do not match our records.');
    app(ReviewService::class)->write('auth.failed', 'es', 'Estos datos no coinciden con nuestros registros.', null, approve: true);
    editEnglishFailed($this->fixture, 'These details do not match anything we have.');

    $arabic = Translation::query()->where('key_id', app(KeyFinder::class)->find('auth.failed')->id)->where('locale', 'ar')->first();

    expect($arabic->approved_source_value)->toBe($original)
        ->and(spanishFailed()->approved_source_value)->toBe('These details do not match our records.');
});

it('confirms a stale translation without changing it, keeping its origin', function () {
    editEnglishFailed($this->fixture, 'These credentials do not match our records!');
    $before = spanishFailed();

    $confirmed = app(ReviewService::class)->confirm($before->id, null, 'cosmetic');

    expect($confirmed->approved_value)->toBe($before->approved_value)
        ->and($confirmed->origin)->toBe(TranslationOrigin::Imported)
        ->and($confirmed->approved_source_value)->toBe('These credentials do not match our records!')
        ->and(WorkState::isStale($confirmed->key, $confirmed))->toBeFalse()
        ->and($confirmed->reviews()->latest('id')->first()->action)->toBe(ReviewAction::Confirmed);
});

it('refuses to confirm a translation that is current', function () {
    expect(fn () => app(ReviewService::class)->confirm(spanishFailed()->id, null))->toThrow(ProsettaException::class);
});
