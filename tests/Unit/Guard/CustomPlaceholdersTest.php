<?php

use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Guard\Placeholders;

beforeEach(fn () => config(['prosetta.placeholders.patterns' => ['/\[@\]/']]));

it('extracts a host\'s own token beside Laravel\'s :name ones', function () {
    expect(Placeholders::unique('Welcome aboard, [@]! You have :count days.'))->toBe([':count', '[@]']);
});

it('flags a translation that drops a host token', function () {
    $issues = (new PlaceholderGuard)->check('Welcome aboard, [@]!', '¡Bienvenido a bordo!', 'es');

    expect(array_map(fn (Issue $issue) => $issue->code, $issues))->toContain('placeholder_missing');
});

it('passes a translation that keeps it', function () {
    expect((new PlaceholderGuard)->check('Welcome aboard, [@]!', '¡Bienvenido a bordo, [@]!', 'es'))->toBe([]);
});

it('ignores a pattern that is not a valid regular expression rather than breaking every check', function () {
    config(['prosetta.placeholders.patterns' => ['/[unclosed/', '/\[@\]/']]);

    expect(Placeholders::unique('Hi [@]'))->toBe(['[@]']);
});

it('keeps a listed term, such as a product name, exactly, without writing a pattern', function () {
    config(['prosetta.placeholders.terms' => ['Undaunted', 'Lonely Lights', 'C++']]);

    expect(Placeholders::unique('Join Undaunted, from Lonely Lights, in C++'))->toBe(['C++', 'Lonely Lights', 'Undaunted'])
        ->and(Placeholders::unique('UNDAUNTED · 2027'))->toBe(['UNDAUNTED'])
        ->and(Placeholders::unique('Undauntedly curious'))->toBe([]);
});

it('flags a translation that translates a listed term', function () {
    config(['prosetta.placeholders.terms' => ['Undaunted']]);

    $issues = (new PlaceholderGuard)->check('Join Undaunted', 'Únete a Intrépidos', 'es');

    expect(array_map(fn (Issue $issue) => $issue->code, $issues))->toContain('placeholder_missing')
        ->and((new PlaceholderGuard)->check('Join Undaunted', 'Únete a Undaunted', 'es'))->toBe([]);
});
