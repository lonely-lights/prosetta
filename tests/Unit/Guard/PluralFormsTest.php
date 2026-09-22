<?php

use LonelyLights\Prosetta\Guard\PluralForms;

it('derives plural form counts from Laravel', function (string $locale, int $forms) {
    expect(PluralForms::count($locale))->toBe($forms);
})->with([
    ['en', 2], ['en_GB', 2], ['es', 2], ['ar', 6], ['zh-CN', 1], ['ja', 1], ['ru', 3],
]);
