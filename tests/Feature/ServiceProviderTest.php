<?php

use LonelyLights\Prosetta\Services\LangKeyService;

describe('ProsettaServiceProvider', function () {
    it('registers the LangKeyService as a singleton', function () {
        $service1 = app(LangKeyService::class);
        $service2 = app(LangKeyService::class);

        expect($service1)->toBeInstanceOf(LangKeyService::class);
        expect($service1)->toBe($service2);
    });

    it('registers the activeLocales singleton', function () {
        $locales = app('activeLocales');

        expect($locales)->toBeArray();
    });

    it('merges prosetta config', function () {
        expect(config('prosetta'))->toBeArray();
        expect(config('prosetta.locales'))->toBeArray();
    });
});
