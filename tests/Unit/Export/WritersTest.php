<?php

use LonelyLights\Prosetta\Export\JsonWriter;
use LonelyLights\Prosetta\Export\PhpArrayWriter;

it('writes readable PHP with short arrays and a header', function () {
    $php = (new PhpArrayWriter)->render(['title' => 'Ajustes', 'steps' => ['Abre el menú', 'Elige un idioma']], "Line one\nLine two");

    expect($php)->toBe(<<<'PHP'
<?php

declare(strict_types=1);

/*
 * Line one
 * Line two
 */

return [
    'title' => 'Ajustes',
    'steps' => [
        'Abre el menú',
        'Elige un idioma',
    ],
];

PHP);
});

it('round-trips awkward characters', function () {
    $values = ['quote' => "It's", 'slash' => 'C:\\path\\', 'newline' => "Two\nlines", 'emoji' => 'Listo ✅', 'rtl' => 'مرحبًا، :name'];
    $path = tempnam(sys_get_temp_dir(), 'prosetta').'.php';
    file_put_contents($path, (new PhpArrayWriter)->render($values));

    expect(require $path)->toBe($values);
    unlink($path);
});

it('writes an empty array compactly', function () {
    expect((new PhpArrayWriter)->render([]))->toBe("<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
});

it('keeps the keys of an integer-keyed array that is not a list', function () {
    expect((new PhpArrayWriter)->render(['levels' => [1 => 'Uno', 3 => 'Tres']]))->toContain("        1 => 'Uno',
        3 => 'Tres',");
});

it('writes JSON with readable unicode and slashes, always as an object', function () {
    expect((new JsonWriter)->render(['Save changes' => 'Guardar cambios', 'a/b' => 'ñ']))
        ->toBe("{\n    \"Save changes\": \"Guardar cambios\",\n    \"a/b\": \"ñ\"\n}\n")
        ->and((new JsonWriter)->render([]))->toBe("{}\n");
});
