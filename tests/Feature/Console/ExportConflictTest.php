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
