<?php

use Illuminate\Support\Facades\Event;
use LonelyLights\Prosetta\Events\TranslationSuspended;
use LonelyLights\Prosetta\Resilience\RunScope;
use LonelyLights\Prosetta\Resilience\Suspensions;

beforeEach(function () {
    config(['prosetta.resilience.cache_store' => 'array']);
    Event::fake([TranslationSuspended::class]);
});

it('identifies a scope by its contents, whatever the order', function () {
    expect((new RunScope(['es', 'ar'], ['identity'], []))->id())->toBe((new RunScope(['ar', 'es'], ['identity'], []))->id())
        ->and((new RunScope(['es'], [], []))->id())->not->toBe((new RunScope(['es'], [], [], force: true))->id())
        ->and(RunScope::fromArray((new RunScope(['es'], ['bridge'], ['auth.failed'], true))->toArray()))->toEqual(new RunScope(['es'], ['bridge'], ['auth.failed'], true));
});

it('merges repeated suspensions of the same scope and raises one event', function () {
    $suspensions = app(Suspensions::class);
    $scope = new RunScope(['es'], ['identity'], []);

    $suspensions->suspend('fake:m', $scope, 'outage');
    $suspensions->suspend('fake:m', new RunScope(['es'], ['identity'], []), 'outage');
    $suspensions->suspend('fake:m', new RunScope(['ar'], [], []), 'rejected');

    expect($suspensions->all())->toHaveCount(2)
        ->and($suspensions->all()["fake:m|{$scope->id()}"]['scope'])->toEqual($scope);
    Event::assertDispatchedTimes(TranslationSuspended::class, 2);
});

it('clears a suspension by id', function () {
    $suspensions = app(Suspensions::class);
    $suspensions->suspend('fake:m', new RunScope(['es'], [], []), 'outage');

    $suspensions->clear(array_key_first($suspensions->all()));

    expect($suspensions->all())->toBe([]);
});
