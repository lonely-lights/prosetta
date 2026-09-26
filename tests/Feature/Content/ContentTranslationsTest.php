<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Review\ReviewService;
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
    $this->pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);
});

it('reads the approved translation for the current locale, and English otherwise', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);

    app()->setLocale('es');
    expect($this->pillar->translated('name'))->toBe('Tecnología')
        ->and($this->pillar->translated('badge'))->toBe('Tech')
        ->and($this->pillar->name)->toBe('Technology');

    app()->setLocale('en');
    expect($this->pillar->translated('name'))->toBe('Technology')
        ->and($this->pillar->translated('name', 'es'))->toBe('Tecnología');
});

it('never serves a draft', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Borrador', null);

    expect($this->pillar->translated('name', 'es'))->toBe('Technology');
});

it('serves a new approval even when the folder was already cached', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);
    expect($this->pillar->translated('name', 'es'))->toBe('Tecnología');

    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnologías', null, approve: true);

    expect($this->pillar->translated('name', 'es'))->toBe('Tecnologías');
});

it('gives every translatable field at once', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);

    expect($this->pillar->translations('es'))->toBe(['name' => 'Tecnología', 'badge' => 'Tech']);
});

it('keeps serving the translation after the slug changes', function () {
    app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null, approve: true);
    expect($this->pillar->translated('name', 'es'))->toBe('Tecnología');

    $this->pillar->update(['slug' => 'tech']);

    expect($this->pillar->translated('name', 'es'))->toBe('Tecnología');
});
