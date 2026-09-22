<?php

use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Models\Locale;

beforeEach(fn () => $this->seedLocales());

it('stores translated separately from active', function () {
    $gb = Locale::findByCode('en_GB');

    expect($gb->translated)->toBeTrue()->and($gb->active)->toBeFalse();
});

it('targets every locale that is active or translated, in sort order', function () {
    expect(Locale::query()->targets()->ordered()->pluck('locale_initials')->all())->toBe(['en', 'es', 'ar', 'en_GB']);
});

it('refuses to change a locale code', function () {
    $es = Locale::findByCode('es');
    $es->locale_initials = 'es-ES';

    expect(fn () => $es->save())->toThrow(LogicException::class, 'immutable');
});

it('still allows changing everything else', function () {
    Locale::findByCode('es')->update(['english_name' => 'Castilian', 'active' => false]);

    expect(Locale::findByCode('es')->english_name)->toBe('Castilian');
});

it('reads its table name from config', function () {
    config()->set('prosetta.table_names.locales', 'custom_locales');

    expect((new Locale)->getTable())->toBe('custom_locales');
});

it('describes itself without exposing the model', function () {
    expect(Locale::findByCode('ar')->toDescriptor())->toEqual(new LocaleDescriptor('ar', 'Arabic', 'العربية', 'Arabic', true));
});

it('moves the default and activates the new default', function () {
    $fr = Locale::findByCode('fr');
    $fr->setAsDefault();

    expect(Locale::getDefaultCode())->toBe('fr')
        ->and($fr->fresh()->active)->toBeTrue()
        ->and(Locale::query()->default()->count())->toBe(1);
});

it('will not deactivate the default locale', function () {
    expect(Locale::findByCode('en')->toggleActive())->toBeFalse()
        ->and(Locale::findByCode('en')->active)->toBeTrue();
});

it('keeps its presentation helpers', function () {
    $ar = Locale::findByCode('ar');

    expect($ar->code)->toBe('ar')
        ->and($ar->getDisplayName())->toBe('العربية (Arabic)')
        ->and($ar->getDirection())->toBe('rtl')
        ->and(Locale::findByCode('en')->getDisplayName())->toBe('English')
        ->and(Locale::getActiveCodes())->toBe(['en', 'es', 'ar']);
});

it('is open to subclassing', function () {
    expect((new ReflectionClass(Locale::class))->isFinal())->toBeFalse();
});
