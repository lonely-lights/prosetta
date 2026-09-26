<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Enums\KeyKind;
use LonelyLights\Prosetta\Support\Fingerprint;
use LonelyLights\Prosetta\Support\KeyRef;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property int $file_id
 * @property KeyKind $kind
 * @property string $key
 * @property string $key_hash
 * @property string $source_value
 * @property string $source_hash
 * @property list<string>|null $placeholders
 * @property string|null $context
 * @property int|null $max_length
 * @property Carbon|null $obsolete_at
 * @property-read TranslationFile $file
 */
class TranslationKey extends Model {
    protected $fillable = ['file_id', 'kind', 'key', 'source_value', 'source_hash', 'placeholders', 'context', 'max_length', 'obsolete_at'];

    protected $casts = [
        'kind' => KeyKind::class,
        'placeholders' => 'array',
        'max_length' => 'integer',
        'obsolete_at' => 'datetime',
    ];

    protected $attributes = ['kind' => 'file'];

    public function getTable(): string {
        return Settings::table('keys');
    }

    protected static function booted(): void {
        static::saving(function (TranslationKey $key): void {
            $key->key_hash = Fingerprint::of($key->key);
        });
    }

    /** @return BelongsTo<TranslationFile, $this> */
    public function file(): BelongsTo {
        return $this->belongsTo(Settings::model('file'), 'file_id');
    }

    /** @return HasMany<Translation, $this> */
    public function translations(): HasMany {
        return $this->hasMany(Settings::model('translation'), 'key_id');
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder {
        return $query->whereNull($query->qualifyColumn('obsolete_at'));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWithKey(Builder $query, string $key): Builder {
        return $query->where($query->qualifyColumn('key_hash'), Fingerprint::of($key));
    }

    public function isObsolete(): bool {
        return $this->obsolete_at !== null;
    }

    public function ref(): KeyRef {
        return new KeyRef($this->file->namespace, $this->file->group, $this->key);
    }
}
