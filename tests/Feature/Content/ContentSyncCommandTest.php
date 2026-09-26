<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Models\TranslationKey;
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
    # Rows That Existed Before the Model Adopted the Trait: Written Without Eloquent, So No Keys Yet
    DB::table('pillars')->insert([
        ['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech', 'draft' => false],
        ['slug' => 'education', 'name' => 'Education', 'badge' => null, 'draft' => false],
    ]);
});

it('gives rows that already exist their keys', function () {
    expect(TranslationKey::query()->count())->toBe(0);

    $this->artisan('prosetta:content:sync', ['model' => [Pillar::class]])
        ->expectsOutput('Synced 2 '.Pillar::class.' records.')
        ->assertExitCode(0);

    expect(TranslationKey::query()->pluck('key')->sort()->values()->all())
        ->toBe(['education.name', 'technology.badge', 'technology.name']);
});

it('syncs the models listed in config when none are named, and is safe to repeat', function () {
    config(['prosetta.content.models' => [Pillar::class]]);

    $this->artisan('prosetta:content:sync')->assertExitCode(0);
    $this->artisan('prosetta:content:sync')->assertExitCode(0);

    expect(TranslationKey::query()->count())->toBe(3);
});

it('refuses a class that does not translate content', function () {
    $this->artisan('prosetta:content:sync', ['model' => [TranslationKey::class]])->assertExitCode(1);
});
