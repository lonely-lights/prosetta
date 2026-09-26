<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
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

    foreach (range(1, 40) as $n) {
        Pillar::query()->create(['slug' => "p$n", 'name' => "Pillar $n", 'badge' => "B$n"]);
    }
});

it('reads only the saved record\'s keys, however many others share its folder', function () {
    $loaded = 0;
    Event::listen('eloquent.retrieved: '.TranslationKey::class, function () use (&$loaded): void {
        $loaded++;
    });

    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);
    Pillar::query()->where('slug', 'technology')->first()->update(['name' => 'Technology!']);

    expect($loaded)->toBeLessThanOrEqual(4);
});

it('still tells apart records whose keys share a prefix', function () {
    Pillar::query()->create(['slug' => 'p4x', 'name' => 'Prefix', 'badge' => 'PX']);
    Pillar::query()->where('slug', 'p4')->first()->delete();

    expect(TranslationKey::query()->where('key', 'p4x.name')->value('obsolete_at'))->toBeNull()
        ->and(TranslationKey::query()->where('key', 'p4.name')->value('obsolete_at'))->not->toBeNull();
});
