<?php

use LonelyLights\Prosetta\Contracts\TranslationDriver;
use LonelyLights\Prosetta\Data\TranslationBatch;
use LonelyLights\Prosetta\Data\TranslationBatchResult;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Queries\KeyFinder;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Testing\ScriptedDriver;
use LonelyLights\Prosetta\Translation\TranslationRunner;

beforeEach(function () {
    $this->useFixtureApp();
    $this->seedLocales();
    app(Syncer::class)->sync();
});

if (! function_exists('keyIds')) {
    function keyIds(string ...$refs): array {
        return array_map(fn (string $ref) => app(KeyFinder::class)->find($ref)->id, $refs);
    }
}

function editEnglish(string $fixture, string $to): void {
    $path = $fixture.'/lang/en/auth.php';
    file_put_contents($path, str_replace("'These credentials do not match our records.'", var_export($to, true), file_get_contents($path)));
    app(Syncer::class)->sync();
}

it('sends the English a translation was approved against as previousSource', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    editEnglish($this->fixture, 'These details do not match our records.');
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    app(TranslationRunner::class)->run('es', keyIds('auth.failed'));

    expect($driver->calls)->toHaveCount(1);
    $item = $driver->calls[0]->items[0];
    expect($item->previousSource)->toBe('These credentials do not match our records.')
        ->and($item->previous)->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('leaves previousSource null for a key with no approved translation', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    $driver = new ScriptedDriver;
    app()->instance(TranslationDriver::class, $driver);

    app(TranslationRunner::class)->run('es', keyIds('auth.throttle'));

    expect($driver->calls)->toHaveCount(1);
    $item = $driver->calls[0]->items[0];
    expect($item->previousSource)->toBeNull();
});

it('flags an update that rewrote far more than the English changed', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    editEnglish($this->fixture, 'These details do not match our records.');
    $driver = new class extends ScriptedDriver {
        public function translate(TranslationBatch $batch): TranslationBatchResult {
            $this->calls[] = $batch;

            return new TranslationBatchResult(
                array_fill_keys(array_map(fn ($item) => $item->id, $batch->items), 'Algo completamente distinto que nadie pidió cambiar hoy'),
                'fake', 'm', 10, 10,
            );
        }
    };
    app()->instance(TranslationDriver::class, $driver);

    $report = app(TranslationRunner::class)->run('es', keyIds('auth.failed'));

    $issues = Translation::query()->where('key_id', keyIds('auth.failed')[0])->where('locale', 'es')->value('issues');
    expect(collect($issues)->pluck('code')->all())->toContain('large_rewrite')
        ->and($report->updated)->toBe(['es auth.failed']);
});

it('accepts a minimal update', function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    editEnglish($this->fixture, 'These details do not match our records.');
    $driver = new class extends ScriptedDriver {
        public function translate(TranslationBatch $batch): TranslationBatchResult {
            $this->calls[] = $batch;

            return new TranslationBatchResult(
                array_fill_keys(array_map(fn ($item) => $item->id, $batch->items), 'Estas credenciales no coinciden con nuestros archivos.'),
                'fake', 'm', 10, 10,
            );
        }
    };
    app()->instance(TranslationDriver::class, $driver);

    $report = app(TranslationRunner::class)->run('es', keyIds('auth.failed'));

    $issues = Translation::query()->where('key_id', keyIds('auth.failed')[0])->where('locale', 'es')->value('issues');
    expect(collect($issues)->pluck('code')->all())->not->toContain('large_rewrite')
        ->and($report->updated)->toBe(['es auth.failed']);
});
