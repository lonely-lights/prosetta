<?php

use LonelyLights\Prosetta\Translation\SourceChange;

it('treats whitespace, quote style, dashes, trailing punctuation and case as cosmetic', function (string $old, string $new) {
    expect(SourceChange::isCosmetic($old, $new))->toBeTrue();
})->with([
    ['Save  changes', 'Save changes'],
    ["Don\u{2019}t go", "Don't go"],  // curly quote U+2019 vs straight apostrophe
    ['A - B', 'A — B'],
    ['Saved', 'Saved.'],
    ['Log In', 'Log in'],
]);

it('treats any word change, including spelling, as substantive', function (string $old, string $new) {
    expect(SourceChange::isCosmetic($old, $new))->toBeFalse();
})->with([
    ['Colour', 'Color'],
    ['Save changes', 'Save all changes'],
    ['Deleted', 'Removed'],
]);

it('writes a word-level diff', function () {
    expect(SourceChange::diff('These credentials do not match our records.', 'These details do not match our records.'))
        ->toBe('These [-credentials-] {+details+} do not match our records.');
});

it('measures how much more the translation changed than the English', function () {
    $small = SourceChange::ratio('Save your changes now', 'Save all your changes now', 'Guarda tus cambios ahora', 'Guarda todos tus cambios ahora');
    $rewrite = SourceChange::ratio('Save your changes now', 'Save all your changes now', 'Guarda tus cambios ahora', 'Almacena por favor todas las modificaciones pendientes');

    expect($small)->toBeLessThan(3.0)
        ->and($rewrite)->toBeGreaterThan(3.0)
        ->and(SourceChange::changedWords('a b c', 'a x c'))->toBe(1);
});
