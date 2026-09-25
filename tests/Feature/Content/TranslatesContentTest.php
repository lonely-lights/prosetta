<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
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
});

function contentKey(string $key): ?TranslationKey {
    return TranslationKey::query()->where('key', $key)->whereHas('file', fn ($q) => $q->where('namespace', 'content')->where('group', 'pillars'))->first();
}

it('creates a content key per translatable field, with its context and length', function () {
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);

    $name = contentKey('technology.name');
    $badge = contentKey('technology.badge');

    expect($name->kind)->toBe(KeyKind::Content)
        ->and($name->source_value)->toBe('Technology')
        ->and($name->context)->toBe('The full name of a pillar.')
        ->and($badge->max_length)->toBe(20)
        ->and($name->ref()->toString())->toBe('content::pillars.technology.name');
});

it('updates the English and leaves approved translations to go stale', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    $key = contentKey('technology.name');
    Translation::query()->create([
        'key_id' => $key->id, 'locale' => 'es', 'value' => 'Tecnología', 'source_hash' => $key->source_hash,
        'approved_value' => 'Tecnología', 'approved_source_hash' => $key->source_hash, 'status' => TranslationStatus::Approved,
    ]);

    $pillar->update(['name' => 'Technology and Tools']);

    expect($key->refresh()->source_value)->toBe('Technology and Tools')
        ->and($key->source_hash)->not->toBe(Translation::query()->where('key_id', $key->id)->value('approved_source_hash'));
});

it('renames keys with the slug, keeping their translations', function () {
    $pillar = Pillar::query()->create(['slug' => 'tech', 'name' => 'Technology']);
    $id = contentKey('tech.name')->id;

    $pillar->update(['slug' => 'technology']);

    expect(contentKey('technology.name')->id)->toBe($id)
        ->and(contentKey('tech.name'))->toBeNull();
});

it('marks a deleted record\'s keys obsolete', function () {
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology'])->delete();

    expect(contentKey('technology.name')->obsolete_at)->not->toBeNull();
});

it('keeps a record that should not translate out of the queue until it should', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'draft' => true]);
    expect(contentKey('technology.name'))->toBeNull();

    $pillar->update(['draft' => false]);
    expect(contentKey('technology.name')->obsolete_at)->toBeNull();

    $pillar->update(['draft' => true]);
    expect(contentKey('technology.name')->obsolete_at)->not->toBeNull();
});

it('drops the key of a field that is emptied, and restores it when filled', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology', 'badge' => 'Tech']);

    $pillar->update(['badge' => null]);
    expect(contentKey('technology.badge')->obsolete_at)->not->toBeNull();

    $pillar->update(['badge' => 'Tech']);
    expect(contentKey('technology.badge')->obsolete_at)->toBeNull();
});

it('creates no keys when the saving transaction rolls back', function () {
    try {
        DB::transaction(function (): void {
            Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(contentKey('technology.name'))->toBeNull();
});
