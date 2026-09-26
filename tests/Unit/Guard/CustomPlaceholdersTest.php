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
