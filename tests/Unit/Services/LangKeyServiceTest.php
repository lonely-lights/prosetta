<?php

use LonelyLights\Prosetta\Services\LangKeyService;

describe('LangKeyService (Deprecated)', function () {
    describe('generatePrefixedKeys', function () {
        it('delegates to KeyManager and returns keys unchanged when no affix provided', function () {
            $keys = ['title', 'description'];
            $result = LangKeyService::generatePrefixedKeys($keys);

            expect($result)->toBe(['title', 'description']);
        });

        it('delegates to KeyManager and applies string affix as prefix by default', function () {
            config(['prosetta.affixationType' => '.']);
            config(['prosetta.affixationDefault' => 'prefix']);

            $keys = ['title', 'description'];
            $result = LangKeyService::generatePrefixedKeys($keys, 'entry-slug');

            expect($result)->toBe(['entry-slug.title', 'entry-slug.description']);
        });
    });

    describe('mapToValues', function () {
        it('delegates to TranslationService and extracts values from model attributes', function () {
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

    describe('backward compatibility', function () {
        it('maintains the same API as before splitting', function () {
            // Verify methods exist and are callable
            expect(method_exists(LangKeyService::class, 'setConfig'))->toBeTrue();
            expect(method_exists(LangKeyService::class, 'manageLanguageFileEntry'))->toBeTrue();
            expect(method_exists(LangKeyService::class, 'generatePrefixedKeys'))->toBeTrue();
            expect(method_exists(LangKeyService::class, 'mapToValues'))->toBeTrue();
            expect(method_exists(LangKeyService::class, 'processKeyValue'))->toBeTrue();
            expect(method_exists(LangKeyService::class, 'removeKeys'))->toBeTrue();
            expect(method_exists(LangKeyService::class, 'updateLangEntries'))->toBeTrue();
        });
    });
});
