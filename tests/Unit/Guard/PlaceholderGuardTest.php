<?php

use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Guard\PlaceholderGuard;
use LonelyLights\Prosetta\Guard\Placeholders;
use LonelyLights\Prosetta\Guard\Severity;

function codes(array $issues): array {
    return array_map(fn (Issue $issue) => $issue->code, $issues);
}

it('extracts placeholders, keeping repeats and stopping at punctuation', function () {
    expect(Placeholders::extract('Registration has a :minutes-minute limit; :name, :name!'))
        ->toBe([':minutes', ':name', ':name'])
        ->and(Placeholders::unique('At 10:30 visit https://x.test'))->toBe([]);
});

it('passes a faithful translation', function () {
    expect((new PlaceholderGuard)->check(
        'Registration has a :minutes-minute limit, so the access code was released.',
        'El registro tiene un límite de :minutes minutos, así que el código de acceso se liberó.',
        'es',
    ))->toBe([]);
});

it('flags a missing placeholder', function () {
    expect(codes((new PlaceholderGuard)->check('Welcome, :name!', '¡Bienvenido!', 'es')))->toBe(['placeholder_missing']);
});

it('flags a placeholder whose case changed, because Laravel formats by case', function () {
    $issues = (new PlaceholderGuard)->check('Welcome, :name!', '¡Bienvenido, :Name!', 'es');

    expect(codes($issues))->toBe(['placeholder_case'])
        ->and($issues[0]->message)->toContain(':Name');
});

it('flags an invented placeholder', function () {
    expect(codes((new PlaceholderGuard)->check('Welcome!', '¡Bienvenido, :nombre!', 'es')))->toBe(['placeholder_unexpected']);
});

it('warns when a placeholder repeats a different number of times', function () {
    $issues = (new PlaceholderGuard)->check(':name and :name', ':name', 'es');

    expect(codes($issues))->toBe(['placeholder_count'])
        ->and($issues[0]->severity)->toBe(Severity::Warning);
});

it('flags an empty translation', function () {
    expect(codes((new PlaceholderGuard)->check('Save', '  ', 'es')))->toBe(['empty_value']);
});

it('requires explicit plural ranges to survive', function () {
    $guard = new PlaceholderGuard;
    $source = '{0} No apples|{1} One apple|[2,*] :count apples';

    expect($guard->check($source, '{0} Sin manzanas|{1} Una manzana|[2,*] :count manzanas', 'es'))->toBe([])
        ->and(codes($guard->check($source, '{0} Sin manzanas|Una manzana|:count manzanas', 'es')))->toBe(['plural_range_missing']);
});

it('checks plain plural segments against the target language', function () {
    $guard = new PlaceholderGuard;
    $source = 'One apple|:count apples';

    expect($guard->check($source, 'Una manzana|:count manzanas', 'es'))->toBe([])
        ->and(codes($guard->check($source, ':count manzanas', 'es')))->toBe(['plural_missing'])
        ->and(codes($guard->check($source, 'a|b|:count c', 'es')))->toBe(['plural_segments_excess'])
        ->and(codes($guard->check($source, 'a|b|c|d|e|:count f', 'ar')))->toBe(['plural_segments_differ'])
        ->and(codes($guard->check($source, ':count 个苹果', 'zh-CN')))->toBe(['plural_segments_differ']);
});

it('matches placeholders by range in explicit plural forms', function () {
    $issues = (new PlaceholderGuard)->check('{1} :n minute|[2,*] :n minutes', '{1} :n 分|[2,*] 分', 'ja');

    expect(codes($issues))->toBe(['placeholder_missing'])
        ->and($issues[0]->message)->toContain('[2,*]');
});

it('passes a faithful explicit-range plural translation', function () {
    $source = '{1} Too many email requests. Please try again in 1 minute.|[2,*] Too many email requests. Please try again in :minutes minutes.';
    $candidate = '{1} Demasiadas solicitudes. Inténtalo de nuevo en 1 minuto.|[2,*] Demasiadas solicitudes. Inténtalo de nuevo en :minutes minutos.';

    expect((new PlaceholderGuard)->check($source, $candidate, 'es'))->toBe([]);
});

it('flags a placeholder moved into the wrong plural range', function () {
    $source = '{1} Too many email requests. Please try again in 1 minute.|[2,*] Too many email requests. Please try again in :minutes minutes.';
    $candidate = '{1} Demasiadas solicitudes. Inténtalo de nuevo en :minutes minuto.|[2,*] Demasiadas solicitudes. Inténtalo de nuevo en minutos.';

    $issues = (new PlaceholderGuard)->check($source, $candidate, 'es');
    $issueCodes = codes($issues);
    sort($issueCodes);
    $messages = implode(' ', array_map(fn (Issue $issue) => $issue->message, $issues));

    expect($issueCodes)->toBe(['placeholder_missing', 'placeholder_unexpected'])
        ->and($messages)->toContain('[2,*]')
        ->and($messages)->toContain('{1}');
});

it('allows a placeholder found in only some source segments to land anywhere in an unmarked plural', function () {
    $source = 'One apple|:count apples';
    $candidate = 'تفاحة واحدة|:count تفاحتان|:count تفاحات|:count تفاحات|:count تفاحات|:count تفاحة';

    expect(codes((new PlaceholderGuard)->check($source, $candidate, 'ar')))->toBe(['plural_segments_differ']);
});

it('requires a placeholder found in every source segment to survive in every unmarked plural segment', function () {
    $source = ':name has one|:name has :count';
    $candidate = ':name tiene una|tiene :count';

    expect(codes((new PlaceholderGuard)->check($source, $candidate, 'es')))->toBe(['placeholder_missing']);
});

it('requires HTML tags to survive in order', function () {
    $guard = new PlaceholderGuard;
    $source = 'Read the <a href=":url">terms</a> first.';

    expect($guard->check($source, 'Lee los <a href=":url">términos</a> primero.', 'es'))->toBe([])
        ->and(codes($guard->check($source, 'Lee los términos primero. :url', 'es')))->toBe(['html_mismatch']);
});

it('stores issues as arrays and knows which block', function () {
    $stored = Issue::store([Issue::warning('placeholder_count', 'x'), Issue::error('placeholder_missing', 'y')]);

    expect($stored)->toBe([
        ['code' => 'placeholder_count', 'severity' => 'warning', 'message' => 'x'],
        ['code' => 'placeholder_missing', 'severity' => 'error', 'message' => 'y'],
    ])
        ->and(Issue::anyBlocking($stored))->toBeTrue()
        ->and(Issue::anyBlocking([$stored[0]]))->toBeFalse()
        ->and(Issue::anyBlocking(null))->toBeFalse()
        ->and(Issue::store([]))->toBeNull()
        ->and(Issue::fromArray($stored[1])->isBlocking())->toBeTrue();
});
