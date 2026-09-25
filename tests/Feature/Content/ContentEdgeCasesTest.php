<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Content\ContentKeys;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Models\TranslationKey;
use LonelyLights\Prosetta\Review\ReviewDesk;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;
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

function approveContent(string $ref, string $value): void {
    app(ReviewService::class)->write($ref, 'es', $value, null, approve: true);
}

it('serves a translation again once its record is published again, though the folder was cached meanwhile', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    approveContent('content::pillars.technology.name', 'Tecnología');

    $pillar->update(['draft' => true]);
    expect($pillar->translated('name', 'es'))->toBe('Technology');

    $pillar->update(['draft' => false]);
    expect($pillar->translated('name', 'es'))->toBe('Tecnología');
});

it('clears the cache only once the approval commits, so a read during the transaction cannot keep the old value', function () {
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);

    DB::transaction(function (): void {
        approveContent('content::pillars.technology.name', 'Tecnología');
        # Another request reads before this commits, and caches what it saw
        Cache::forever('prosetta.content.pillars.es', []);
    });

    expect($pillar->translated('name', 'es'))->toBe('Tecnología');
});

it('reuses a deleted record\'s slug without colliding with its leftover keys', function () {
    Pillar::query()->create(['slug' => 'tech', 'name' => 'Old tech'])->delete();
    $pillar = Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    approveContent('content::pillars.technology.name', 'Tecnología');

    $pillar->update(['slug' => 'tech']);

    $keys = TranslationKey::query()->where('key', 'tech.name')->get();
    expect($keys)->toHaveCount(1)
        ->and($keys->first()->source_value)->toBe('Technology')
        ->and($keys->first()->obsolete_at)->toBeNull()
        ->and($pillar->translated('name', 'es'))->toBe('Tecnología');
});

it('retires the key of a field the model no longer translates', function () {
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    $file = app(ContentKeys::class)->file('pillars');
    $leftover = TranslationKey::query()->create([
        'file_id' => $file->id, 'kind' => KeyKind::Content, 'key' => 'technology.subtitle',
        'source_value' => 'Old subtitle', 'source_hash' => sha1('Old subtitle'),
    ]);

    Pillar::query()->first()->update(['name' => 'Technology!']);

    expect($leftover->refresh()->obsolete_at)->not->toBeNull();
});

it('counts file items a batch left alone because review is read-only here', function () {
    $this->useFixtureApp();
    app(Syncer::class)->sync();
    app(Authorizer::class)->using(fn () => true);
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    $content = app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null);
    $file = Translation::query()->where('locale', 'es')->whereHas('key', fn ($q) => $q->where('kind', 'file'))->first();
    $file->update(['status' => TranslationStatus::Draft, 'value' => 'Borrador']);
    config(['prosetta.review.editable' => false]);
    $viewer = Viewer::for(new GenericUser(['id' => 'u1']));

    $many = app(ReviewDesk::class)->approveMany($viewer, [
        $content->id => ReviewService::fingerprint($content),
        $file->id => ReviewService::fingerprint($file),
    ]);
    $file->refresh();
    $matching = app(ReviewDesk::class)->approveMatching($viewer, ['locale' => 'es']);

    expect($many->locked)->toBe(1)
        ->and($many->toArray())->toHaveKey('locked')
        ->and($matching->locked)->toBeGreaterThanOrEqual(1);
});
