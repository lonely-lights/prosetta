<?php

use LonelyLights\Prosetta\Support\KeyRef;

it('parses a module key', function () {
    $ref = KeyRef::parse('identity::onboarding.toast.accessCode.inUse');

    expect($ref->namespace)->toBe('identity')
        ->and($ref->group)->toBe('onboarding')
        ->and($ref->key)->toBe('toast.accessCode.inUse');
});

it('parses a root key and a nested-folder group', function () {
    expect(KeyRef::parse('auth.failed'))->toEqual(new KeyRef('*', 'auth', 'failed'))
        ->and(KeyRef::parse('admin/settings.steps.0'))->toEqual(new KeyRef('*', 'admin/settings', 'steps.0'));
});

it('keeps dots and double colons inside JSON keys', function () {
    $ref = KeyRef::parse('json:Version 2.0 is ready. See docs::intro');

    expect($ref)->toEqual(new KeyRef('*', '*', 'Version 2.0 is ready. See docs::intro'))
        ->and($ref->isJson())->toBeTrue();
});

it('round-trips every form', function (string $ref) {
    expect(KeyRef::parse($ref)->toString())->toBe($ref)
        ->and((string) KeyRef::parse($ref))->toBe($ref);
})->with([
    'identity::onboarding.toast.accessCode.capReached',
    'auth.throttle',
    'admin/settings.title',
    'json:Save changes',
]);

it('rejects references it cannot place', function (string $ref) {
    expect(fn () => KeyRef::parse($ref))->toThrow(InvalidArgumentException::class);
})->with(['auth', '::auth.failed', 'identity::onboarding', '.failed', 'auth.', 'json:']);
