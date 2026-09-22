<?php

use LonelyLights\Prosetta\Support\LocaleCode;

it('reads the language subtag', function (string $code, string $language) {
    expect(LocaleCode::language($code))->toBe($language);
})->with([
    ['en', 'en'], ['en_GB', 'en'], ['zh-CN', 'zh'], ['ca-ES-valencia', 'ca'], ['PT_br', 'pt'],
]);

it('knows a regional variant of the same language', function () {
    expect(LocaleCode::isVariantOf('en_GB', 'en'))->toBeTrue()
        ->and(LocaleCode::isVariantOf('en_US', 'en_GB'))->toBeTrue()
        ->and(LocaleCode::isVariantOf('en', 'en'))->toBeFalse()
        ->and(LocaleCode::isVariantOf('es', 'en'))->toBeFalse();
});
