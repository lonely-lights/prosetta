<?php

namespace LonelyLights\Prosetta\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use LonelyLights\Prosetta\Models\Locale;

/**
 * Prosetta Install Command
 *
 * Interactive installer for Prosetta translation package.
 *
 * @package LonelyLights\Prosetta\Console\Commands
 */
class InstallCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prosetta:install
        {--stack=blade : UI stack to use (blade, livewire, filament)}
        {--publish-views : Publish views for customization}
        {--skip-migrations : Skip running migrations}
        {--skip-locales : Skip creating default locales}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure Prosetta translation package';

    /**
     * Default locales to create.
     *
     * @var array
     */
    protected array $defaultLocales = [
        ['locale_initials' => 'en', 'english_name' => 'English', 'native_name' => 'English', 'is_default' => true],
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int {
        $this->info('');
        $this->info('  ____                     _   _        ');
        $this->info(' |  _ \ _ __ ___  ___  ___| |_| |_ __ _ ');
        $this->info(" | |_) | '__/ _ \/ __|/ _ \ __| __/ _` |");
        $this->info(' |  __/| | | (_) \__ \  __/ |_| || (_| |');
        $this->info(' |_|   |_|  \___/|___/\___|\__|\__\__,_|');
        $this->info('');
        $this->info(' Welcome to Prosetta Translation Manager!');
        $this->info('');

        // Step 1: Publish config
        $this->publishConfig();

        // Step 2: Run migrations
        if (!$this->option('skip-migrations')) {
            $this->runMigrations();
        }

        // Step 3: Ask for stack preference (if interactive)
        $stack = $this->getStackChoice();

        // Step 4: Publish views based on stack
        $this->publishViews($stack);

        // Step 5: Create default locales
        if (!$this->option('skip-locales')) {
            $this->createDefaultLocales();
        }

        // Step 6: Ask to run initial sync
        $this->offerInitialSync();

        // Done!
        $this->displayCompletionMessage($stack);

        return self::SUCCESS;
    }

    /**
     * Publish the configuration file.
     *
     * @return void
     */
    protected function publishConfig(): void {
        $this->components->task('Publishing configuration', function () {
            Artisan::call('vendor:publish', [
                '--tag' => 'prosetta-config',
                '--force' => true,
            ]);
            return true;
        });
    }

    /**
     * Run the package migrations.
     *
     * @return void
     */
    protected function runMigrations(): void {
        $this->components->task('Publishing migrations', function () {
            Artisan::call('vendor:publish', [
                '--tag' => 'prosetta-migrations',
            ]);
            return true;
        });

        $runMigrations = !$this->input->isInteractive() || $this->confirm('Run database migrations now?', true);

        if ($runMigrations) {
            $this->components->task('Running migrations', function () {
                Artisan::call('migrate');
                return true;
            });
        } else {
            $this->info('  Skipping migrations. Run "php artisan migrate" when ready.');
        }
    }

    /**
     * Get the stack choice from user or option.
     *
     * @return string
     */
    protected function getStackChoice(): string {
        $stack = $this->option('stack');

        if ($this->input->isInteractive()) {
            $stack = $this->choice(
                'Which UI stack would you like to use?',
                [
                    'blade' => 'Blade + Alpine.js (recommended, works everywhere)',
                    'livewire' => 'Livewire (enhanced interactivity)',
                    'filament' => 'Filament (for Filament admin panels)',
                ],
                'blade'
            );
        }

        // Validate stack
        if (!in_array($stack, ['blade', 'livewire', 'filament'])) {
            $this->warn("Unknown stack '$stack', defaulting to 'blade'.");
            $stack = 'blade';
        }

        // Check if dependencies are available
        if ($stack === 'livewire' && !class_exists('Livewire\\Livewire')) {
            $this->warn('Livewire is not installed. Install it with: composer require livewire/livewire');
            $this->warn('Falling back to Blade stack.');
            $stack = 'blade';
        }

        if ($stack === 'filament' && !class_exists('Filament\\FilamentServiceProvider')) {
            $this->warn('Filament is not installed. Install it from: https://filamentphp.com');
            $this->warn('Falling back to Blade stack.');
            $stack = 'blade';
        }

        // Update the config file with the selected stack
        $this->updateConfigStack($stack);

        return $stack;
    }

    /**
     * Update the published config file with the selected stack.
     *
     * @param string $stack
     * @return void
     */
    protected function updateConfigStack(string $stack): void {
        $configPath = config_path('prosetta.php');

        if (!file_exists($configPath)) {
            return;
        }

        $this->components->task('Configuring UI stack', function () use ($configPath, $stack) {
            $contents = file_get_contents($configPath);

            // Replace the stack value in the config
            $contents = preg_replace(
                "/'stack'\s*=>\s*'[a-z]+'/",
                "'stack' => '$stack'",
                $contents
            );

            file_put_contents($configPath, $contents);

            return true;
        });
    }

    /**
     * Publish views based on the selected stack.
     *
     * @param string $stack
     * @return void
     */
    protected function publishViews(string $stack): void {
        // Check for --publish-views option or ask interactively
        $publishViews = $this->option('publish-views');

        if (!$publishViews && $this->input->isInteractive()) {
            $this->newLine();
            $this->line('  <comment>Note:</comment> Prosetta includes built-in views that work out of the box.');
            $this->line('  Publishing views is only needed if you want to customize them.');
            $publishViews = $this->confirm('Would you like to publish the views for customization?');
        }

        if (!$publishViews) {
            $this->info('  Using built-in package views. You can publish later with:');
            $this->line('  <info>php artisan vendor:publish --tag=prosetta-views</info>');
            return;
        }

        // Publish base Blade views
        $this->components->task('Publishing Blade views', function () {
            Artisan::call('vendor:publish', [
                '--tag' => 'prosetta-views',
                '--force' => true,
            ]);
            return true;
        });

        // Publish stack-specific views
        if ($stack === 'livewire') {
            $this->components->task('Publishing Livewire components', function () {
                Artisan::call('vendor:publish', [
                    '--tag' => 'prosetta-livewire',
                    '--force' => true,
                ]);
                return true;
            });
        }

        if ($stack === 'filament') {
            $this->components->task('Publishing Filament resources', function () {
                Artisan::call('vendor:publish', [
                    '--tag' => 'prosetta-filament',
                    '--force' => true,
                ]);
                return true;
            });
        }
    }

    /**
     * Create default locales in the database.
     *
     * @return void
     */
    protected function createDefaultLocales(): void {
        $this->components->task('Creating default locales', function () {
            foreach ($this->defaultLocales as $localeData) {
                Locale::firstOrCreate(
                    ['locale_initials' => $localeData['locale_initials']],
                    $localeData
                );
            }
            return true;
        });

        // Offer to add more locales
        if ($this->input->isInteractive()) {
            $addMore = $this->confirm('Would you like to add more locales?');

            if ($addMore) {
                $this->addAdditionalLocales();
            }
        }
    }

    /**
     * Allow user to add additional locales.
     *
     * @return void
     */
    protected function addAdditionalLocales(): void {
        $commonLocales = [
            'es' => ['english_name' => 'Spanish', 'native_name' => 'Espanol'],
            'fr' => ['english_name' => 'French', 'native_name' => 'Francais'],
            'de' => ['english_name' => 'German', 'native_name' => 'Deutsch'],
            'it' => ['english_name' => 'Italian', 'native_name' => 'Italiano'],
            'pt' => ['english_name' => 'Portuguese', 'native_name' => 'Portugues'],
            'ja' => ['english_name' => 'Japanese', 'native_name' => 'Nihongo'],
            'zh' => ['english_name' => 'Chinese', 'native_name' => 'Zhongwen'],
            'ko' => ['english_name' => 'Korean', 'native_name' => 'Hangugeo'],
            'ar' => ['english_name' => 'Arabic', 'native_name' => 'Arabi', 'rtl' => true],
            'ru' => ['english_name' => 'Russian', 'native_name' => 'Russkiy'],
        ];

        $choices = ['skip' => 'Skip - no additional locales'];
        foreach ($commonLocales as $code => $data) {
            $choices[$code] = "$code - {$data['english_name']}";
        }
        $choices['custom'] = 'Enter a custom locale';

        $selected = $this->choice(
            'Select locales to add (comma-separated for multiple, or press Enter to skip)',
            $choices,
            'skip',
            null,
            true
        );

        // Handle skip selection
        if (in_array('skip', $selected) || empty($selected)) {
            return;
        }

        foreach ($selected as $code) {
            if ($code === 'skip') {
                continue;
            }

            if ($code === 'custom') {
                $code = $this->ask('Enter locale code (e.g., "nl" for Dutch)');
                $englishName = $this->ask('Enter English name');
                $nativeName = $this->ask('Enter native name');
                $rtl = $this->confirm('Is this a right-to-left language?');

                Locale::firstOrCreate(
                    ['locale_initials' => $code],
                    [
                        'locale_initials' => $code,
                        'english_name' => $englishName,
                        'native_name' => $nativeName,
                        'rtl' => $rtl,
                        'active' => true,
                    ]
                );

                $this->info("  Added locale: $code");
            } elseif (isset($commonLocales[$code])) {
                $data = $commonLocales[$code];
                Locale::firstOrCreate(
                    ['locale_initials' => $code],
                    [
                        'locale_initials' => $code,
                        'english_name' => $data['english_name'],
                        'native_name' => $data['native_name'],
                        'rtl' => $data['rtl'] ?? false,
                        'active' => true,
                    ]
                );

                $this->info("  Added locale: $code - {$data['english_name']}");
            }
        }
    }

    /**
     * Offer to run an initial sync of language files.
     *
     * @return void
     */
    protected function offerInitialSync(): void {
        $runSync = !$this->input->isInteractive() || $this->confirm('Run initial sync of language files?', true);

        if ($runSync) {
            $this->components->task('Syncing language files', function () {
                Artisan::call('prosetta:sync', ['--all' => true]);
                return true;
            });
        }
    }

    /**
     * Display the completion message.
     *
     * @param string $stack
     * @return void
     */
    protected function displayCompletionMessage(string $stack): void {
        $this->newLine();
        $this->info('Prosetta has been installed successfully!');
        $this->newLine();

        $this->line('<comment>Next steps:</comment>');
        $this->line('  1. Visit <info>/prosetta</info> to access the translation management UI');
        $this->line('  2. Run <info>php artisan prosetta:sync en</info> to import existing translations');
        $this->line('  3. Use <info>php artisan prosetta:stats</info> to view translation progress');
        $this->newLine();

        $this->line('<comment>Available commands:</comment>');
        $this->line('  php artisan prosetta:sync {locale}     Sync language files to database');
        $this->line('  php artisan prosetta:export {locale}   Export database to language files');
        $this->line('  php artisan prosetta:stats {locale}    Show translation statistics');
        $this->line('  php artisan prosetta:review {locale}   List items needing attention');
        $this->newLine();

        if ($stack !== 'blade') {
            $this->line("<comment>Stack: $stack</comment>");
            if ($stack === 'livewire') {
                $this->line('  Livewire components are available for enhanced interactivity.');
            } elseif ($stack === 'filament') {
                $this->line('  Filament resources are registered for your admin panel.');
            }
            $this->newLine();
        }

        $this->line('Documentation: <info>https://github.com/lonely-lights/prosetta</info>');
        $this->newLine();
    }
}
