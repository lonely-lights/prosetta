<?php

use LonelyLights\Prosetta\Services\LangKeyService;

describe('LangKeyService', function () {
    describe('generatePrefixedKeys', function () {
        it('returns keys unchanged when no affix provided', function () {
            $keys = ['title', 'description'];
            $result = LangKeyService::generatePrefixedKeys($keys);

            expect($result)->toBe(['title', 'description']);
        });

        it('applies string affix as prefix by default', function () {
            config(['prosetta.affixationType' => '.']);
            config(['prosetta.affixationDefault' => 'prefix']);

            $keys = ['title', 'description'];
            $result = LangKeyService::generatePrefixedKeys($keys, 'entry-slug');

            expect($result)->toBe(['entry-slug.title', 'entry-slug.description']);
        });
    });

    describe('mapToValues', function () {
        it('extracts values from model attributes', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = [
                    'title' => 'Test Title',
                    'description' => 'Test Description',
                ];
            };

            $keys = ['title', 'description'];
            $result = LangKeyService::mapToValues($keys, $model);

            expect($result)->toBe(['Test Title', 'Test Description']);
        });
    });
});
