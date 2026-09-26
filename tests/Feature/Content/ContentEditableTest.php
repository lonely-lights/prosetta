<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Auth\Authorizer;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Exceptions\ReviewLocked;
use LonelyLights\Prosetta\Models\Translation;
use LonelyLights\Prosetta\Review\ReviewDesk;
use LonelyLights\Prosetta\Review\ReviewQueue;
use LonelyLights\Prosetta\Review\ReviewService;
use LonelyLights\Prosetta\Review\Viewer;
use LonelyLights\Prosetta\Sync\Syncer;
use LonelyLights\Prosetta\Tests\Fixtures\Models\Pillar;

beforeEach(function () {
    $this->useFixtureApp();
    app(Authorizer::class)->using(fn () => true);
    $this->seedLocales();
    app(Syncer::class)->sync();
    Schema::create('pillars', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->unique();
        $table->string('name');
        $table->string('badge')->nullable();
        $table->boolean('draft')->default(false);
        $table->timestamps();
    });
    Pillar::query()->create(['slug' => 'technology', 'name' => 'Technology']);
    $this->content = app(ReviewService::class)->write('content::pillars.technology.name', 'es', 'Tecnología', null);
    $this->file = Translation::query()->where('locale', 'es')->whereHas('key', fn ($q) => $q->where('kind', 'file'))->first();
    $this->file->update(['status' => TranslationStatus::Draft, 'value' => 'Borrador']);
    $this->user = new GenericUser(['id' => 'u1']);
    config(['prosetta.review.editable' => false]);
});

it('lets a person approve and edit content where file translations are locked', function () {
    app(ReviewService::class)->approve([$this->content->id], $this->user);
    expect($this->content->refresh()->status)->toBe(TranslationStatus::Approved);

    app(ReviewService::class)->edit($this->content->id, 'Tecnologías', $this->user);
    expect($this->content->refresh()->value)->toBe('Tecnologías');

    expect(fn () => app(ReviewService::class)->approve([$this->file->id], $this->user))->toThrow(ReviewLocked::class);
});

it('approves the content in a mixed batch and skips the locked file item', function () {
    $viewer = Viewer::for($this->user);
    $report = app(ReviewDesk::class)->approveMany($viewer, [
        $this->content->id => ReviewService::fingerprint($this->content),
        $this->file->id => ReviewService::fingerprint($this->file),
    ]);

    expect($report->approved)->toBe(1)
        ->and($report->skipped[$this->file->id])->toBe('locked')
        ->and($this->file->refresh()->status)->toBe(TranslationStatus::Draft);
});

it('approves only content when approving clean items while locked', function () {
    $report = app(ReviewService::class)->approveClean('es', by: $this->user);

    expect($report->approved)->toBe([$this->content->id]);
});

it('marks each queue item with whether it can be changed here', function () {
    $items = collect(app(ReviewQueue::class)->all(Viewer::for($this->user), ['locale' => 'es']))->keyBy('translationId');

    expect($items[$this->content->id]->editable)->toBeTrue()
        ->and($items[$this->file->id]->editable)->toBeFalse();
});
