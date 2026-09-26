<?php

use LonelyLights\Prosetta\Export\SourceComments;

it('reads a file\'s heading and the comment above each key, at any depth', function () {
    $source = <<<'PHP'
<?php

declare(strict_types=1);

# The Twelve Pillars: Their Names and Badges
return [

    // The first pillar
    'arts' => [
        # Its full name
        'name' => 'Arts',
        'badge' => 'Culture',
    ],

    /* A block note */
    'science' => 'Science',
    'plain' => 'No comment here',
];
PHP;

    $comments = SourceComments::fromSource($source);

    expect($comments->header)->toBe('# The Twelve Pillars: Their Names and Badges')
        ->and($comments->keys)->toBe([
            'arts' => '// The first pillar',
            'arts.name' => '# Its full name',
            'science' => '/* A block note */',
        ]);
});

it('finds nothing in a file without comments, or one that isn\'t PHP', function () {
    expect(SourceComments::fromSource("<?php\n\nreturn ['a' => 'b'];\n")->keys)->toBe([])
        ->and(SourceComments::fromSource('not php')->header)->toBeNull();
});

it('leaves a comment trailing a line with that line, not the key below it', function () {
    $comments = SourceComments::fromSource("<?php\n\nreturn [\n    'a' => 'b', // about a\n    'c' => 'd',\n];\n");

    expect($comments->keys)->toBe([]);
});

it('reads a single-quoted key literally, as PHP does', function () {
    # The Key Is 'a\nb' in Single Quotes: a Backslash and an n, Not a Newline
    $comments = SourceComments::fromSource("<?php\n\nreturn [\n    // note\n    'a\\nb' => 'x',\n];\n");

    expect(array_keys($comments->keys))->toBe(['a\\nb']);
});
