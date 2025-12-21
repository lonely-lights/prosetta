<?php

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LonelyLights\Prosetta\Events\TranslationFileCreated;

/**
 * Translation File Model
 *
 * Represents a translation file (e.g., auth.php, profile.php) with its metadata.
 *
 * @property int $id
 * @property string $path                  Unique path identifier (e.g., 'auth', 'profile')
 * @property string $name                  Human-readable name
 * @property string|null $description      File description for documentation
 * @property string|null $category         Category grouping (e.g., 'User Account', 'Core')
 * @property array|null $metadata          Additional structured data
 * @property bool $is_system               Whether this is a system file
 * @property \Carbon\Carbon|null $last_synced_at
 * @property \Carbon\Carbon|null $last_exported_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|TranslationKey[] $keys
 * @property-read int|null $keys_count
 *
 * @package LonelyLights\Prosetta\Models
 */
class TranslationFile extends Model
{
    use SoftDeletes;

    /**
     * Create a new model instance.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('prosetta.tableNames.files', 'prosetta_files');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'path',
        'name',
        'description',
        'category',
        'metadata',
        'is_system',
        'last_synced_at',
        'last_exported_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'is_system' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_exported_at' => 'datetime',
    ];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::created(function (TranslationFile $file) {
            TranslationFileCreated::dispatch($file);
        });
    }

    /**
     * Get the translation keys for this file.
     *
     * @return HasMany
     */
    public function keys(): HasMany
    {
        return $this->hasMany(TranslationKey::class, 'file_id');
    }

    /**
     * Scope to filter by category.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter system files.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param bool $isSystem
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSystem($query, bool $isSystem = true)
    {
        return $query->where('is_system', $isSystem);
    }

    /**
     * Scope to find files that need syncing.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedsSync($query)
    {
        return $query->whereNull('last_synced_at')
            ->orWhere('last_synced_at', '<', now()->subDay());
    }

    /**
     * Get translation statistics for this file.
     *
     * @param string|null $locale Specific locale or null for all
     * @return array
     */
    public function getStatistics(?string $locale = null): array
    {
        $query = $this->keys()->withCount([
            'translations',
            'translations as draft_count' => fn($q) => $q->where('status', 'draft'),
            'translations as needs_review_count' => fn($q) => $q->where('status', 'needs_review'),
            'translations as approved_count' => fn($q) => $q->where('status', 'approved'),
        ]);

        if ($locale) {
            $query->whereHas('translations', fn($q) => $q->where('locale', $locale));
        }

        $keys = $query->get();

        return [
            'total_keys' => $keys->count(),
            'translated' => $keys->sum('translations_count'),
            'draft' => $keys->sum('draft_count'),
            'needs_review' => $keys->sum('needs_review_count'),
            'approved' => $keys->sum('approved_count'),
        ];
    }

    /**
     * Mark the file as synced.
     *
     * @return bool
     */
    public function markAsSynced(): bool
    {
        return $this->update(['last_synced_at' => now()]);
    }

    /**
     * Mark the file as exported.
     *
     * @return bool
     */
    public function markAsExported(): bool
    {
        return $this->update(['last_exported_at' => now()]);
    }

    /**
     * Get the full file path for a given locale.
     *
     * @param string $locale
     * @return string
     */
    public function getFullPath(string $locale): string
    {
        return lang_path("{$locale}/{$this->path}.php");
    }
}
