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

it('groups adjacent removed words', function () {
    expect(SourceChange::diff('A B C D', 'A D'))
        ->toBe('A [-B C-] D');
});

it('groups adjacent added words', function () {
    expect(SourceChange::diff('A D', 'A B C D'))
        ->toBe('A {+B C+} D');
});

it('handles mixed removals and additions', function () {
    $result = SourceChange::diff('Save your changes', 'Keep all your edits');
    // LCS tie-breaking produces: [-Save-] {+Keep all+} your [-changes-] {+edits+}
    expect($result)->toBe('[-Save-] {+Keep all+} your [-changes-] {+edits+}');
});

it('measures how much more the translation changed than the English', function () {
    $small = SourceChange::ratio('Save your changes now', 'Save all your changes now', 'Guarda tus cambios ahora', 'Guarda todos tus cambios ahora');
    $rewrite = SourceChange::ratio('Save your changes now', 'Save all your changes now', 'Guarda tus cambios ahora', 'Almacena por favor todas las modificaciones pendientes');

    expect($small)->toBeLessThan(3.0)
        ->and($rewrite)->toBeGreaterThan(3.0)
        ->and(SourceChange::changedWords('a b c', 'a x c'))->toBe(1);
});

it('tokenizes CJK characters individually', function () {
    // Chinese: 你的访问码已过期 → 你的访问码已失效
    // Change: 过期 → 失效 (2 characters changed)
    expect(SourceChange::changedWords('你的访问码已过期', '你的访问码已失效'))->toBe(2);
});

it('measures ratio with CJK text', function () {
    // Source: 'Save your changes now' → 'Save all your changes now' (1 change / 6 tokens = ~0.167)
    // Translation: '立即保存你的更改' → '立即保存你的所有更改' (1 change / 6 tokens = ~0.167)
    // Ratio should be ~1.0 < 3.0
    $ratio = SourceChange::ratio(
        'Save your changes now',
        'Save all your changes now',
        '立即保存你的更改',
        '立即保存你的所有更改'
    );
    expect($ratio)->toBeLessThan(3.0);
});

it('detects real Arabic word changes as substantive', function () {
    // حفظ التغييرات (Save changes) vs حفظ جميع التغييرات (Save all changes)
    // 'جميع' (all) is a different word, so not cosmetic
    expect(SourceChange::isCosmetic('حفظ التغييرات', 'حفظ جميع التغييرات'))->toBeFalse();
});

it('treats Arabic trailing punctuation as cosmetic', function () {
    // تم الحفظ (Saved) vs تم الحفظ. (Saved with period) - only trailing punctuation differs
    expect(SourceChange::isCosmetic('تم الحفظ', 'تم الحفظ.'))->toBeTrue();
});
