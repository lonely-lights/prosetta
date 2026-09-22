<?php

use LonelyLights\Prosetta\Discovery\LangReader;
use LonelyLights\Prosetta\Discovery\LangRoot;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Exceptions\LangFileException;

beforeEach(fn () => $this->useFixtureApp());

function rootOf(string $fixture): LangRoot {
    return new LangRoot('*', $fixture.'/lang');
}

it('lists PHP groups, nested folders and the JSON file', function () {
    expect(app(LangReader::class)->groups(rootOf($this->fixture), 'en'))->toBe([
        ['group' => 'admin/settings', 'format' => FileFormat::Php],
        ['group' => 'auth', 'format' => FileFormat::Php],
        ['group' => 'messages', 'format' => FileFormat::Php],
        ['group' => '*', 'format' => FileFormat::Json],
    ]);
});

it('lists module groups without JSON', function () {
    $root = new LangRoot('identity', $this->fixture.'/modules/Identity/Lang');

    expect(app(LangReader::class)->groups($root, 'en'))->toBe([['group' => 'onboarding', 'format' => FileFormat::Php]]);
});

it('flattens nested arrays in source order', function () {
    $root = new LangRoot('identity', $this->fixture.'/modules/Identity/Lang');

    expect(array_keys(app(LangReader::class)->read($root, 'en', 'onboarding', FileFormat::Php)))->toBe([
        'toast.accessCode.capReached', 'toast.accessCode.inUse', 'toast.accessCode.timedOut',
    ])
        ->and(app(LangReader::class)->read(rootOf($this->fixture), 'en', 'admin/settings', FileFormat::Php))->toBe([
            'title' => 'Settings', 'steps.0' => 'Open the menu', 'steps.1' => 'Choose a language',
        ]);
});

it('skips values that are not strings', function () {
    file_put_contents($this->fixture.'/lang/en/odd.php', "<?php return ['a' => 'A', 'n' => null, 'b' => true, 'i' => 3, 'e' => [], 'z' => 'Z'];");

    expect(app(LangReader::class)->read(rootOf($this->fixture), 'en', 'odd', FileFormat::Php))->toBe(['a' => 'A', 'z' => 'Z'])
        ->and(app(LangReader::class)->read(rootOf($this->fixture), 'en', 'messages', FileFormat::Php))->not->toHaveKey('limit');
});

it('keeps JSON keys whole', function () {
    expect(app(LangReader::class)->read(rootOf($this->fixture), 'en', '*', FileFormat::Json))->toBe([
        'Save changes' => 'Save changes', 'Version 2.0 is ready.' => 'Version 2.0 is ready.',
    ]);
});

it('returns nothing for a file that does not exist', function () {
    expect(app(LangReader::class)->read(rootOf($this->fixture), 'ar', 'auth', FileFormat::Php))->toBe([]);
});

it('builds the path a group lives at', function () {
    $reader = app(LangReader::class);

    expect($reader->path(rootOf($this->fixture), 'es', 'admin/settings', FileFormat::Php))->toBe($this->fixture.'/lang/es/admin/settings.php')
        ->and($reader->path(rootOf($this->fixture), 'es', '*', FileFormat::Json))->toBe($this->fixture.'/lang/es.json');
});

it('names the file when it cannot be read', function (string $contents) {
    file_put_contents($this->fixture.'/lang/en/broken.php', $contents);

    expect(fn () => app(LangReader::class)->read(rootOf($this->fixture), 'en', 'broken', FileFormat::Php))
        ->toThrow(LangFileException::class, 'broken.php');
})->with(['<?php return [', '<?php return "not an array";']);
