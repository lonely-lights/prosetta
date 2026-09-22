<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Enums\TranslationOrigin;
use LonelyLights\Prosetta\Enums\TranslationStatus;
use LonelyLights\Prosetta\Guard\Issue;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property int $key_id
 * @property string $locale
 * @property string|null $value
 * @property string|null $source_hash
 * @property string|null $approved_value
 * @property string|null $approved_source_hash
 * @property TranslationStatus $status
 * @property TranslationOrigin $origin
 * @property list<array{code: string, severity: string, message: string}>|null $issues
 * @property string|null $ai_provider
 * @property string|null $ai_model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $ai_invocation_id
 * @property string|null $exported_hash
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read TranslationKey $key
 */
class Translation extends Model {
    protected $fillable = [
        'key_id', 'locale', 'value', 'source_hash', 'approved_value', 'approved_source_hash',
        'status', 'origin', 'issues', 'ai_provider', 'ai_model', 'input_tokens', 'output_tokens',
        'ai_invocation_id', 'exported_hash', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'status' => TranslationStatus::class,
        'origin' => TranslationOrigin::class,
        'issues' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'draft', 'origin' => 'manual'];

    public function getTable(): string {
        return Settings::table('translations');
    }

    public function key(): BelongsTo {
        return $this->belongsTo(Settings::model('key'), 'key_id');
    }

    public function reviews(): HasMany {
        return $this->hasMany(Settings::model('review'), 'translation_id');
    }

    public function hasBlockingIssues(): bool {
        return Issue::anyBlocking($this->issues);
    }
}
