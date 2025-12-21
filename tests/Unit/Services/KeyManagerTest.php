<?php

use LonelyLights\Prosetta\Services\KeyManager;

describe('KeyManager', function () {
    describe('generatePrefixedKeys', function () {
        it('returns keys unchanged when no affix provided', function () {
            $keys = ['title', 'description'];
            $result = KeyManager::generatePrefixedKeys($keys);

            expect($result)->toBe(['title', 'description']);
        });

        it('applies string affix as prefix by default', function () {
            config(['prosetta.affixationType' => '.']);
            config(['prosetta.affixationDefault' => 'prefix']);

            $keys = ['title', 'description'];
            $result = KeyManager::generatePrefixedKeys($keys, 'entry-slug');

            expect($result)->toBe(['entry-slug.title', 'entry-slug.description']);
        });

        it('applies string affix as suffix when configured', function () {
            config(['prosetta.affixationType' => '.']);
            config(['prosetta.affixationDefault' => 'suffix']);
            config(['prosetta.suffix' => 'suffix']);

            $keys = ['title'];
            $result = KeyManager::generatePrefixedKeys($keys, 'my-suffix');

            expect($result)->toBe(['title.my-suffix']);
        });
    });

    describe('applyAffixation', function () {
        beforeEach(function () {
            config(['prosetta.affixationType' => '.']);
            config(['prosetta.affixationDefault' => 'prefix']);
            config(['prosetta.prefix' => 'prefix']);
            config(['prosetta.suffix' => 'suffix']);
            config(['prosetta.only' => 'only']);
        });

        it('applies string affix as prefix', function () {
            $result = KeyManager::applyAffixation('blog-post', 'title');

            expect($result)->toBe('blog-post.title');
        });

        it('handles array affix with prefix position', function () {
            $result = KeyManager::applyAffixation(['my-prefix', 'prefix'], 'title');

            expect($result)->toBe('my-prefix.title');
        });

        it('handles array affix with suffix position', function () {
            $result = KeyManager::applyAffixation(['my-suffix', 'suffix'], 'title');

            expect($result)->toBe('title.my-suffix');
        });

        it('handles array affix with only position', function () {
            $result = KeyManager::applyAffixation(['custom-key', 'only'], 'title');

            expect($result)->toBe('custom-key');
        });

        it('applies both affixes when neither position specified', function () {
            $result = KeyManager::applyAffixation(['prefix-part', 'suffix-part'], 'title');

            expect($result)->toBe('prefix-part.title.suffix-part');
        });

        it('returns key unchanged for invalid affix', function () {
            $result = KeyManager::applyAffixation(['single-element'], 'title');

            expect($result)->toBe('title');
        });
    });

    describe('hasAffixChanged', function () {
        it('returns false when affixAttribute is null', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = ['slug' => 'test'];
            };

            $result = KeyManager::hasAffixChanged($model, null);

            expect($result)->toBeFalse();
        });

        it('returns true when affix attribute is dirty', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = ['slug' => 'new-slug'];

                public function isDirty($attribute = null): bool
                {
                    return $attribute === 'slug';
                }
            };

            $result = KeyManager::hasAffixChanged($model, 'slug');

            expect($result)->toBeTrue();
        });

        it('returns false when affix attribute is not dirty', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = ['slug' => 'test'];

                public function isDirty($attribute = null): bool
                {
                    return false;
                }
            };

            $result = KeyManager::hasAffixChanged($model, 'slug');

            expect($result)->toBeFalse();
        });

        it('checks multiple attributes when array provided', function () {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                public function isDirty($attribute = null): bool
                {
                    return $attribute === 'category';
                }
            };

            $result = KeyManager::hasAffixChanged($model, ['slug', 'category']);

            expect($result)->toBeTrue();
        });
    });
});
