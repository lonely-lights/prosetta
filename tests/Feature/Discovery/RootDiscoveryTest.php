<?php

use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Discovery\RootDiscovery;

beforeEach(fn () => $this->useFixtureApp());

function rootMap(array $roots): array {
    return collect($roots)->mapWithKeys(fn (LangRoot $root) => [$root->namespace => $root->path])->all();
}

it('discovers the root lang folder and module namespaces, skipping excluded paths', function () {
    expect(rootMap(app(RootDiscovery::class)->roots()))->toBe([
        '*' => $this->fixture.'/lang',
        'identity' => $this->fixture.'/modules/Identity/Lang',
    ]);
});

it('filters namespaces by include, exclude and an explicit list', function () {
    config()->set('prosetta.namespaces.exclude', ['identity']);
    expect(array_keys(rootMap(app(RootDiscovery::class)->roots())))->toBe(['*']);

    config()->set('prosetta.namespaces.exclude', []);
    config()->set('prosetta.namespaces.include', ['nothing']);
    expect(array_keys(rootMap(app(RootDiscovery::class)->roots())))->toBe(['*']);

    config()->set('prosetta.namespaces.include', ['*']);
    expect(array_keys(rootMap(app(RootDiscovery::class)->roots(['identity']))))->toBe(['identity']);
});

it('lets config add or override namespace paths', function () {
    config()->set('prosetta.namespaces.discover', false);
    config()->set('prosetta.paths', ['identity' => $this->fixture.'/modules/Identity/Lang', 'ghost' => $this->fixture.'/missing']);

    expect(rootMap(app(RootDiscovery::class)->roots()))->toBe([
        '*' => $this->fixture.'/lang',
        'identity' => $this->fixture.'/modules/Identity/Lang',
    ]);
});
