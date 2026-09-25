<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Tests\Fixtures\Models\Pillar;

beforeEach(function () {
    $this->seedLocales();
    Schema::create('pillars', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->unique();
        $table->string('name');
        $table->string('badge')->nullable();
        $table->boolean('draft')->default(false);
        $table->timestamps();
    });
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);
    $this->path = tempnam(sys_get_temp_dir(), 'pillars').'.php';
    file_put_contents($this->path, "<?php return ['technology' => ['name' => 'Tecnología', 'badge' => 'Tec'], 'retired' => ['name' => 'Viejo']];");
});

afterEach(fn () => @unlink($this->path));

it('imports a lang file as approved translations of matching content keys', function () {
    $this->artisan('prosetta:content:import', ['folder' => 'pillars', 'locale' => 'es', 'path' => $this->path])
        ->expectsOutput('Imported 2, unmatched 1, flagged 0.')
        ->assertExitCode(0);

    expect(Translation::query()->where('locale', 'es')->where('status', TranslationStatus::Approved)->count())->toBe(2)
        ->and(Pillar::query()->first()->translated('name', 'es'))->toBe('Tecnología');
});

it('fails on a missing file', function () {
    $this->artisan('prosetta:content:import', ['folder' => 'pillars', 'locale' => 'es', 'path' => '/nope.php'])
        ->assertExitCode(1);
});
