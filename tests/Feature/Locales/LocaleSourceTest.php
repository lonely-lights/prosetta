<?php

use LonelyLights\Prosetta\Contracts\LocaleSource;
use LonelyLights\Prosetta\Locales\ConfigLocaleSource;
use LonelyLights\Prosetta\Locales\DatabaseLocaleSource;

it('reads targets from the locales table, excluding the source', function () {
    $this->seedLocales();
    $source = app(LocaleSource::class);

    expect($source)->toBeInstanceOf(DatabaseLocaleSource::class)
        ->and($source->source())->toBe('en')
        ->and(array_map(fn ($l) => $l->code, $source->targets()))->toBe(['es', 'ar', 'en_GB'])
        ->and($source->find('ar')?->rtl)->toBeTrue()
        ->and($source->find('fr')?->englishName)->toBe('French')
        ->and($source->find('xx'))->toBeNull();
});

it('falls back to a config list for apps without the table', function () {
    config()->set('prosetta.locales.source', ConfigLocaleSource::class);
    config()->set('prosetta.locales.fallback', ['en', 'es', 'ar']);
    $source = app(LocaleSource::class);

    expect($source)->toBeInstanceOf(ConfigLocaleSource::class)
        ->and(array_map(fn ($l) => $l->code, $source->targets()))->toBe(['es', 'ar'])
        ->and($source->find('ar')?->rtl)->toBeTrue()
        ->and($source->find('es')?->rtl)->toBeFalse()
        ->and($source->find('de'))->toBeNull();
});
