<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Exceptions\ProsettaException;
use LonelyLights\Prosetta\Export\JsonWriter;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Translation\TranslationRunner;

it('reports a value JSON cannot encode as a Prosetta error', function () {
    expect(fn () => (new JsonWriter)->render(['bad' => "\xB1\x31"]))
        ->toThrow(ProsettaException::class, 'JSON');
});

it('explains a TranslationDriver the container cannot build', function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
    app()->bind(TranslationDriver::class, 'App\\Missing\\NoSuchDriver');

    expect(fn () => app(TranslationRunner::class)->run('es', [app(KeyFinder::class)->find('auth.throttle')->id]))
        ->toThrow(ProsettaException::class, 'TranslationDriver');
});

it('keeps numeric JSON keys as strings through sync', function () {
    $this->useFixtureApp();
    $this->seedLocales();
    file_put_contents($this->fixture.'/lang/en.json', json_encode(['404' => 'Not found', 'Save changes' => 'Save changes']));

    $report = app(Syncer::class)->sync();

    expect($report->added)->toContain('json:404')
        ->and(TranslationKey::query()->withKey('404')->first()?->ref()->toString())->toBe('json:404');
});
