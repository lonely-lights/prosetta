<?php

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
});

it('fails an export that would overwrite unsynced edits, and lets --force override', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    file_put_contents($this->fixture.'/lang/es/auth.php', "<?php return ['failed' => 'Credenciales incorrectas.'];");

    $this->artisan('prosetta:export --locale=es')
        ->expectsOutputToContain('Conflict')
        ->assertFailed();

    expect(require $this->fixture.'/lang/es/auth.php')->toBe(['failed' => 'Credenciales incorrectas.']);

    $this->artisan('prosetta:export --locale=es --force')->assertSuccessful();

    expect(require $this->fixture.'/lang/es/auth.php')->toBe(['failed' => 'Estas credenciales no coinciden con nuestros registros.']);
});

it('says which target files it left alone because their source group is gone', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    unlink($this->fixture.'/lang/en/auth.php');
    $this->artisan('prosetta:sync')->assertSuccessful();

    $this->artisan('prosetta:export --locale=es')
        ->expectsOutputToContain('source group no longer exists')
        ->assertSuccessful();
});
