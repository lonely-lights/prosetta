<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use LonelyLights\Prosetta\Data\LocaleDescriptor;
use LonelyLights\Prosetta\Support\Settings;

/**
 * A locale Prosetta knows. "active" means offered to members; "translated"
 * means Prosetta maintains its strings even when members cannot pick it.
 * Codes are immutable: translations, lang folders and host records all
 * refer to a locale by its code. Hosts attach their own language data with
 * Locale::resolveRelationUsing() or by subclassing through config.
 *
 * @property int $id
 * @property string $locale_initials
 * @property string $english_name
 * @property string $native_name
 * @property string|null $script
 * @property bool $rtl
 * @property bool $active
 * @property bool $translated
 * @property bool $is_default
 * @property int $sort_order
 * @property-read string $code
 *
 * @method static Builder<static> active()
 * @method static Builder<static> default()
 * @method static Builder<static> ordered()
 * @method static Builder<static> targets()
 */
class Locale extends Model {
    protected $fillable = [
        'locale_initials', 'english_name', 'native_name', 'script', 'rtl',
        'active', 'translated', 'is_default', 'sort_order',
    ];

    protected $casts = [
        'rtl' => 'boolean',
        'active' => 'boolean',
        'translated' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getTable(): string {
        return Settings::table('locales');
    }

    protected static function booted(): void {
        static::updating(function (Locale $locale): void {
            if ($locale->isDirty('locale_initials')) {
                throw new LogicException(sprintf(
                    'Locale codes are immutable; cannot change [%s] to [%s].',
                    $locale->getOriginal('locale_initials'),
                    $locale->locale_initials,
                ));
            }
        });
    }

    public function scopeActive(Builder $query): Builder {
        return $query->where('active', true);
    }

    public function scopeDefault(Builder $query): Builder {
        return $query->where('is_default', true);
    }

    public function scopeOrdered(Builder $query): Builder {
        return $query->orderBy('sort_order')->orderBy('english_name');
    }

    /** Locales Prosetta maintains: offered to members or explicitly translated. */
    public function scopeTargets(Builder $query): Builder {
        return $query->where(fn (Builder $inner) => $inner->where('active', true)->orWhere('translated', true));
    }

    /** @return list<string> */
    public static function getActiveCodes(): array {
        return static::query()->active()->ordered()->pluck('locale_initials')->all();
    }

    public static function getDefault(): ?static {
        return static::query()->default()->first();
    }

    public static function getDefaultCode(): ?string {
        return static::getDefault()?->locale_initials;
    }

    public static function findByCode(string $code): ?static {
        return static::query()->where('locale_initials', $code)->first();
    }

    public function setAsDefault(): bool {
        static::query()->where('is_default', true)->whereKeyNot($this->getKey())->update(['is_default' => false]);

        return $this->update(['is_default' => true, 'active' => true]);
    }

    public function toggleActive(): bool {
        if ($this->is_default && $this->active) {
            return false;
        }

        return $this->update(['active' => ! $this->active]);
    }

    public function getCodeAttribute(): string {
        return $this->locale_initials;
    }

    public function getDisplayName(): string {
        return $this->native_name === $this->english_name
            ? $this->english_name
            : "$this->native_name ($this->english_name)";
    }

    public function isRtl(): bool {
        return $this->rtl;
    }

    public function getDirection(): string {
        return $this->rtl ? 'rtl' : 'ltr';
    }

    public function toDescriptor(): LocaleDescriptor {
        return new LocaleDescriptor($this->locale_initials, $this->english_name, $this->native_name, $this->script, $this->rtl);
    }
}
