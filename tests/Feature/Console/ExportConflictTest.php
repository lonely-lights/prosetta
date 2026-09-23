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

it('treats a key it renamed as its own, dropping the old key instead of reporting a conflict', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:export --locale=es')->assertSuccessful();
    $source = $this->fixture.'/modules/Identity/Lang/en/onboarding.php';
    file_put_contents($source, str_replace("'inUse' =>", "'inUseElsewhere' =>", file_get_contents($source)));
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:rename identity::onboarding.toast.accessCode.inUse identity::onboarding.toast.accessCode.inUseElsewhere')->assertSuccessful();

    $this->artisan('prosetta:export --locale=es')->doesntExpectOutputToContain('Conflict')->assertSuccessful();

    $accessCode = (require $this->fixture.'/modules/Identity/Lang/es/onboarding.php')['toast']['accessCode'];
    expect($accessCode)->not->toHaveKey('inUse')
        ->and($accessCode['inUseElsewhere'])->toStartWith('Este código de acceso');
});

it('still reports an unknown key whose value Prosetta never wrote', function () {
    $this->artisan('prosetta:sync')->assertSuccessful();
    $this->artisan('prosetta:export --locale=es')->assertSuccessful();
    $target = $this->fixture.'/modules/Identity/Lang/es/onboarding.php';
    $values = require $target;
    $values['toast']['accessCode']['addedByHand'] = 'Escrito a mano.';
    file_put_contents($target, '<?php return '.var_export($values, true).';');

    $this->artisan('prosetta:export --locale=es')->expectsOutputToContain('addedByHand')->assertFailed();
});
