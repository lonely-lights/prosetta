<?php

use LonelyLights\Prosetta\Export\Exporter;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Sync\Syncer;

it('copies the English file\'s comments into each translated file, above the same keys', function () {
    $directory = $this->useFixtureApp();
    $this->seedLocales();
    file_put_contents($directory.'/lang/en/auth.php', "<?php\n\n# Signing In: What People See When It Goes Wrong\nreturn [\n    // Wrong email or password\n    'failed' => 'These credentials do not match our records.',\n    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',\n];\n");
    app(Syncer::class)->sync();
    app(ReviewService::class)->write('auth.throttle', 'es', 'Demasiados intentos. Espera :seconds segundos.', null, approve: true);

    app(Exporter::class)->export(['es'], force: true);
    $spanish = file_get_contents($directory.'/lang/es/auth.php');

    expect($spanish)->toContain("# Signing In: What People See When It Goes Wrong\n")
        ->and($spanish)->toContain("    // Wrong email or password\n    'failed' => 'Estas credenciales no coinciden con nuestros registros.',")
        ->and(require $directory.'/lang/es/auth.php')->toBe([
            'failed' => 'Estas credenciales no coinciden con nuestros registros.',
            'throttle' => 'Demasiados intentos. Espera :seconds segundos.',
        ]);
});
