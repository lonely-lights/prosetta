<?php

use LonelyLights\Prosetta\Translation\WordReplacer;

const BRITISH = [
    ['from' => 'color', 'to' => 'colour'],
    ['from' => 'colors', 'to' => 'colours'],
    ['from' => 'center', 'to' => 'centre'],
    ['from' => 'centered', 'to' => 'centred'],
    ['from' => 'gray', 'to' => 'grey'],
];

it('replaces whole words and keeps their capitalization', function () {
    expect(WordReplacer::apply('Pick a color. Colors change. COLOR MODE. Centered in the center.', BRITISH))
        ->toBe('Pick a colour. Colours change. COLOUR MODE. Centred in the centre.');
});

it('leaves words that only contain a listed word alone', function () {
    expect(WordReplacer::apply('A colorful graying epicenter.', BRITISH))->toBe('A colorful graying epicenter.');
});

it('leaves placeholders, HTML tags and URLs alone', function () {
    expect(WordReplacer::apply('Set :color to <span class="center gray">gray</span> at https://example.com/color/center.', BRITISH))
        ->toBe('Set :color to <span class="center gray">grey</span> at https://example.com/color/center.');
});

it('returns the text unchanged with no replacements', function () {
    expect(WordReplacer::apply('Pick a color.', []))->toBe('Pick a color.');
});
