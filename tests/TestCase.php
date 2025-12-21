<?php

namespace LonelyLights\Prosetta\Tests;

use LonelyLights\Prosetta\ProsettaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up any leftover test files
        $this->cleanupLangDirectory();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $this->cleanupLangDirectory();

        parent::tearDown();
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ProsettaServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        // Disable database for unit tests (use null driver)
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Use array cache (no database needed)
        $app['config']->set('cache.default', 'array');

        // Prosetta configuration
        $app['config']->set('prosetta.locales', ['en', 'es', 'fr']);
        $app['config']->set('prosetta.defaultLocale', 'en');
        $app['config']->set('prosetta.logChannel', 'null');
        $app['config']->set('prosetta.tableNames', [
            'locales' => 'prosetta_locales',
            'files' => 'prosetta_files',
            'keys' => 'prosetta_keys',
            'translations' => 'prosetta_translations',
            'reviews' => 'prosetta_reviews',
        ]);
    }

    /**
     * Clean up the test lang directory.
     *
     * @return void
     */
    protected function cleanupLangDirectory(): void
    {
        $langPath = $this->app->langPath();

        if (!is_dir($langPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($langPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
    }

    /**
     * Create a test language file.
     *
     * @param string $locale
     * @param string $path
     * @param array $content
     * @return string
     */
    protected function createLangFile(string $locale, string $path, array $content = []): string
    {
        $fullPath = $this->app->langPath("$locale/$path.php");
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $output = "<?php\n\nreturn " . var_export($content, true) . ";\n";
        file_put_contents($fullPath, $output);

        return $fullPath;
    }

    /**
     * Get the contents of a language file.
     *
     * @param string $locale
     * @param string $path
     * @return array|null
     */
    protected function getLangFile(string $locale, string $path): ?array
    {
        $fullPath = $this->app->langPath("$locale/$path.php");

        if (!file_exists($fullPath)) {
            return null;
        }

        return include $fullPath;
    }
}
