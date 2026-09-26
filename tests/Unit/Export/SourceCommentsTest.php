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
