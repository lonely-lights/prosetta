<?php

use LonelyLights\Prosetta\Services\TranslationService;

describe('TranslationService', function () {
    describe('setConfig and getAffixAttribute', function () {
        it('stores and retrieves affix attribute configuration', function () {
            TranslationService::setConfig(['affixAttribute' => 'slug']);

            expect(TranslationService::getAffixAttribute())->toBe('slug');
        });

        it('handles array affix attributes', function () {
            TranslationService::setConfig(['affixAttribute' => ['slug', 'category']]);

            expect(TranslationService::getAffixAttribute())->toBe(['slug', 'category']);
        });

        it('returns null when not configured', function () {
            TranslationService::setConfig([]);

            expect(TranslationService::getAffixAttribute())->toBeNull();
        });
    });

    describe('mapToValues', function () {
        it('extracts values from model attributes', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = [
                    'title' => 'Test Title',
                    'description' => 'Test Description',
                    'other' => 'Not Extracted',
                ];
            };

            $keys = ['title', 'description'];
            $result = TranslationService::mapToValues($keys, $model);

            expect($result)->toBe(['Test Title', 'Test Description']);
        });

        it('returns null for missing attributes', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = ['title' => 'Test'];
            };

            $keys = ['title', 'missing_key'];
            $result = TranslationService::mapToValues($keys, $model);

            expect($result[0])->toBe('Test');
            expect($result[1])->toBeNull();
        });
    });

    describe('getModelDetails', function () {
        it('extracts string modelDescription', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                public string $modelDescription = 'This is a blog post';
            };

            $result = TranslationService::getModelDetails($model, 'title');

            expect($result['description'])->toBe('This is a blog post');
        });

        it('extracts array modelDescription by key', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                public array $modelDescription = [
                    'title' => 'The post title',
                    'body' => 'The post content',
                ];
            };

            $result = TranslationService::getModelDetails($model, 'title');

            expect($result['description'])->toBe('The post title');
        });

        it('returns null when modelDescription not present', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
            };

            $result = TranslationService::getModelDetails($model, 'title');

            expect($result['description'])->toBeNull();
        });

        it('returns null for missing key in array modelDescription', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                public array $modelDescription = ['title' => 'Title desc'];
            };

            $result = TranslationService::getModelDetails($model, 'missing_key');

            expect($result['description'])->toBeNull();
        });
    });

    describe('buildKeyValuePairs', function () {
        beforeEach(function () {
            config(['prosetta.affixationType' => '.']);
            config(['prosetta.affixationDefault' => 'prefix']);
        });

        it('builds pairs with affix applied', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = ['title' => 'New Title'];

                public function isDirty($attribute = null): bool
                {
                    return $attribute === 'title';
                }

                public function getOriginal($key = null, $default = null): mixed
                {
                    return 'Old Title';
                }
            };

            $result = TranslationService::buildKeyValuePairs(
                ['title'],
                $model,
                'post-slug',
                null
            );

            expect($result[0]['affixedKey'])->toBe('post-slug.title');
            expect($result[0]['originalKey'])->toBe('title');
            expect($result[0]['value'])->toBe('New Title');
            expect($result[0]['oldValue'])->toBe('Old Title');
            expect($result[0]['isDirty'])->toBeTrue();
        });

        it('includes captured data from other locales', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = ['title' => 'Title'];

                public function isDirty($attribute = null): bool
                {
                    return false;
                }

                public function getOriginal($key = null, $default = null): mixed
                {
                    return 'Title';
                }
            };

            $capturedData = [
                'es' => ['title' => 'Titulo'],
                'fr' => ['title' => 'Titre'],
            ];

            $result = TranslationService::buildKeyValuePairs(
                ['title'],
                $model,
                null,
                null,
                $capturedData
            );

            expect($result[0]['es'])->toBe('Titulo');
            expect($result[0]['fr'])->toBe('Titre');
        });
    });
});
