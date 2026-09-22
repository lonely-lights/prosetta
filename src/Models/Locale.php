<?php

namespace LonelyLights\Prosetta\Models;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Locale Model
 *
 * Represents an active locale in the translation system.
 * This model uses the existing Prosetta column naming for backward compatibility.
 *
 * @property int $id
 * @property string $locale_initials       Locale code (e.g., 'en', 'es', 'fr')
 * @property string $english_name          English name (e.g., 'English', 'Spanish')
 * @property string $native_name           Native name (e.g., 'English', 'Español')
 * @property string|null $script           Writing script (e.g., 'Latin', 'Arabic')
 * @property bool $rtl                     Whether locale is right-to-left
 * @property bool $active                  Whether locale is offered to members
 * @property bool $translated              Whether Prosetta maintains translations for it, even when not offered
 * @property bool $is_default              Whether this is the default locale
 * @property int $sort_order               Display order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder|static active()
 * @method static Builder|static default()
 * @method static Builder|static ordered()
 *
 * @mixin Builder
 *
 * @package LonelyLights\Prosetta\Models
 */
class Locale extends Model {
    /**
     * Create a new model instance.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = []) {
        parent::__construct($attributes);
        $this->table = config('prosetta.tableNames.locales', 'prosetta_locales');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'locale_initials',
        'english_name',
        'native_name',
        'script',
        'rtl',
        'active',
        'translated',
        'is_default',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'rtl' => 'boolean',
        'active' => 'boolean',
        'translated' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Scope to get active locales.
     *
     * @api
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder {
        return $query->where('active', true);
    }

    /**
     * Scope to get the default locale.
     *
     * @api
     * @param Builder $query
     * @return Builder
     */
    public function scopeDefault(Builder $query): Builder {
        return $query->where('is_default', true);
    }

    /**
     * Scope to order by sort order.
     *
     * @api
     * @param Builder $query
     * @return Builder
     */
    public function scopeOrdered(Builder $query): Builder {
        return $query->orderBy('sort_order')->orderBy('english_name');
    }

    /**
     * Get all active locale codes.
     *
     * @return array
     */
    public static function getActiveCodes(): array {
        return static::active()->ordered()->pluck('locale_initials')->toArray();
    }

    /**
     * Get active locales (cached for 60 minutes).
     *
     * This is the legacy method for backward compatibility.
     *
     * @api
     * @return array
     */
    public static function getActiveLocales(): array {
        return Cache::remember('activeLocales', 60, function () {
            try {
                return static::where('active', true)
                    ->select(['locale_initials', 'english_name', 'native_name'])
                    ->get()
                    ->keyBy('locale_initials')
                    ->toArray();
            } catch (Exception $e) {
                Log::channel(config('prosetta.logChannel', 'default'))->error(
                    '[Prosetta] Failed to fetch active locales. Defaulting to English.',
                    ['error' => $e->getMessage()]
                );

                return ['en' => ['english_name' => 'English', 'native_name' => 'English']];
            }
        });
    }

    /**
     * Get the default locale.
     *
     * @return static|null
     */
    public static function getDefault(): ?static {
        return static::default()->first();
    }

    /**
     * Get the default locale code.
     *
     * @api
     * @return string|null
     */
    public static function getDefaultCode(): ?string {
        return static::getDefault()?->locale_initials;
    }

    /**
     * Find a locale by its code.
     *
     * @api
     * @param string $code
     * @return static|null
     */
    public static function findByCode(string $code): ?static {
        return static::where('locale_initials', $code)->first();
    }

    /**
     * Set this locale as the default.
     *
     * @api
     * @return bool
     */
    public function setAsDefault(): bool {
        // Remove default from other locales
        static::where('is_default', true)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        return $this->update(['is_default' => true, 'active' => true]);
    }

    /**
     * Toggle the active status.
     *
     * @api
     * @return bool
     */
    public function toggleActive(): bool {
        // Can't deactivate the default locale
        if ($this->is_default && $this->active) {
            return false;
        }

        return $this->update(['active' => !$this->active]);
    }

    /**
     * Get the locale code (alias for locale_initials).
     *
     * @api
     * @return string
     */
    public function getCodeAttribute(): string {
        return $this->locale_initials;
    }

    /**
     * Get the display name (native name with English in parentheses if different).
     *
     * @api
     * @return string
     */
    public function getDisplayName(): string {
        if ($this->native_name === $this->english_name) {
            return $this->english_name;
        }

        return "$this->native_name ($this->english_name)";
    }

    /**
     * Check if this is a right-to-left locale.
     *
     * @api
     * @return bool
     */
    public function isRtl(): bool {
        return $this->rtl;
    }

    /**
     * Get the direction for CSS/HTML.
     *
     * @api
     * @return string
     */
    public function getDirection(): string {
        return $this->rtl ? 'rtl' : 'ltr';
    }

    /**
     * Clear the active locales cache.
     *
     * @api
     * @return void
     */
    public static function clearCache(): void {
        Cache::forget('activeLocales');
    }
}
