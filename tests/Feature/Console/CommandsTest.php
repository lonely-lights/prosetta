<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Testing\FakeTranslationDriver;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
});

it('fails the check while work is outstanding', function () {
    $this->artisan('prosetta:sync --check')->assertExitCode(1);
});

it('syncs, translates, approves and exports from the console', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);

    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:translate --locale=es --namespace=identity --sync')->assertSuccessful();
    $this->artisan('prosetta:review es --approve-clean')->assertSuccessful();
    $this->artisan('prosetta:export --locale=es --namespace=identity')->assertSuccessful();

    expect(require $this->fixture.'/modules/Identity/Lang/es/onboarding.php')->toHaveCount(1)
        ->and(file_get_contents($this->fixture.'/modules/Identity/Lang/es/onboarding.php'))->toContain(':minutes-minute limit');
});

it('lists the review queue', function () {
    app()->instance(TranslationDriver::class, new FakeTranslationDriver);
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:translate --locale=es --namespace=identity --sync')->assertSuccessful();

    $this->artisan('prosetta:review es')
        ->expectsOutputToContain('identity::onboarding.toast.accessCode.capReached')
        ->assertSuccessful();
});

it('previews an export without writing', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:export --locale=es --dry-run')
        ->expectsOutputToContain('would write')
        ->assertSuccessful();
});

it('prints stats and refuses a bad rename', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:stats --locale=es')->expectsOutputToContain('identity')->assertSuccessful();
    $this->artisan('prosetta:rename auth.failed auth.nope')->assertFailed();
});

it('publishes config and migrations on install', function () {
    $this->artisan('prosetta:install')->assertSuccessful();

    expect(is_file(config_path('prosetta.php')))->toBeTrue();
    unlink(config_path('prosetta.php'));
    foreach (glob(database_path('migrations/*prosetta*.php')) as $migration) {
        unlink($migration);
    }
});
