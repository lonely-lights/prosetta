<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

use LonelyLights\Prosetta\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeTranslationKey', function () {
    return $this->toBeString()
        ->toMatch('/^[a-zA-Z0-9-._]+$/');
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createTestLangFile(string $locale, string $path, array $content = []): string
{
    $fullPath = lang_path("$locale/$path.php");
    $directory = dirname($fullPath);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $output = "<?php\n\nreturn " . var_export($content, true) . ";\n";
    file_put_contents($fullPath, $output);

    return $fullPath;
}

function cleanupTestLangFiles(): void
{
    $langPath = lang_path();
    if (is_dir($langPath)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($langPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }
}
