<?php

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LonelyLights\Prosetta\Events\TranslationKeyCreated;

/**
 * Translation Key Model
 *
 * Represents an individual translation key with its metadata.
 *
 * @property int $id
 * @property int $file_id                  Foreign key to translation file
 * @property string $key                   Translation key (e.g., 'settings.save_button')
 * @property string|null $description      Developer notes about this key
 * @property string|null $context          Usage context for translators
 * @property array|null $placeholders      Dynamic values (e.g., [':name', ':count'])
 * @property int|null $max_length          UI constraint for length
 * @property bool $is_html                 Whether value contains HTML markup
 * @property bool $is_deprecated           Whether key is deprecated
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @property-read TranslationFile $file
 * @property-read \Illuminate\Database\Eloquent\Collection|Translation[] $translations
 * @property-read int|null $translations_count
 *
 * @package LonelyLights\Prosetta\Models
 */
class TranslationKey extends Model
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
        $this->table = config('prosetta.tableNames.keys', 'prosetta_keys');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'file_id',
        'key',
        'description',
        'context',
        'placeholders',
        'max_length',
        'is_html',
        'is_deprecated',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'placeholders' => 'array',
        'max_length' => 'integer',
        'is_html' => 'boolean',
        'is_deprecated' => 'boolean',
    ];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::created(function (TranslationKey $key) {
            TranslationKeyCreated::dispatch($key);
        });
    }

    /**
     * Get the translation file this key belongs to.
     *
     * @return BelongsTo
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(TranslationFile::class, 'file_id');
    }

    /**
     * Get all translations for this key.
     *
     * @return HasMany
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class, 'key_id');
    }

    /**
     * Scope to filter deprecated keys.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param bool $deprecated
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeprecated($query, bool $deprecated = true)
    {
        return $query->where('is_deprecated', $deprecated);
    }

    /**
     * Scope to filter keys with placeholders.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithPlaceholders($query)
    {
        return $query->whereNotNull('placeholders');
    }

    /**
     * Scope to filter HTML-enabled keys.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeHtml($query)
    {
        return $query->where('is_html', true);
    }

    /**
     * Scope to find keys missing translation for a locale.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $locale
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMissingTranslation($query, string $locale)
    {
        return $query->whereDoesntHave('translations', fn($q) => $q->where('locale', $locale));
    }

    /**
     * Get the translation for a specific locale.
     *
     * @param string $locale
     * @return Translation|null
     */
    public function getTranslation(string $locale): ?Translation
    {
        return $this->translations()->where('locale', $locale)->first();
    }

    /**
     * Get the translated value for a specific locale.
     *
     * @param string $locale
     * @param string|null $fallback
     * @return string|null
     */
    public function getValue(string $locale, ?string $fallback = null): ?string
    {
        $translation = $this->getTranslation($locale);

        return $translation?->value ?? $fallback;
    }

    /**
     * Set the translation value for a specific locale.
     *
     * @param string $locale
     * @param string $value
     * @param string $source
     * @return Translation
     */
    public function setTranslation(string $locale, string $value, string $source = 'manual'): Translation
    {
        return $this->translations()->updateOrCreate(
            ['locale' => $locale],
            ['value' => $value, 'source' => $source]
        );
    }

    /**
     * Get the full key identifier (file.key format).
     *
     * @return string
     */
    public function getFullKey(): string
    {
        return $this->file->path . '.' . $this->key;
    }

    /**
     * Mark this key as deprecated.
     *
     * @return bool
     */
    public function deprecate(): bool
    {
        return $this->update(['is_deprecated' => true]);
    }

    /**
     * Validate that a value contains all required placeholders.
     *
     * @param string $value
     * @return array Missing placeholders
     */
    public function validatePlaceholders(string $value): array
    {
        if (empty($this->placeholders)) {
            return [];
        }

        $missing = [];
        foreach ($this->placeholders as $placeholder) {
            if (!str_contains($value, $placeholder)) {
                $missing[] = $placeholder;
            }
        }

        return $missing;
    }
}
