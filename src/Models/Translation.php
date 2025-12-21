<?php

namespace LonelyLights\Prosetta\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LonelyLights\Prosetta\Events\TranslationApproved;
use LonelyLights\Prosetta\Events\TranslationNeedsReview;
use LonelyLights\Prosetta\Events\TranslationRejected;
use LonelyLights\Prosetta\Events\TranslationUpdated;

/**
 * Translation Model
 *
 * Represents a translated value for a specific key and locale.
 *
 * @property int $id
 * @property int $key_id                   Foreign key to translation key
 * @property string $locale                Locale code (e.g., 'en', 'es')
 * @property string $value                 The translated string
 * @property string $status                Translation status (draft, needs_review, approved, rejected)
 * @property string $source                Translation source (manual, ai, imported)
 * @property float|null $confidence        AI confidence score (0.0-1.0)
 * @property int|null $reviewed_by         User ID who reviewed
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read TranslationKey $key
 * @property-read Collection|TranslationReview[] $reviews
 * @property-read int|null $reviews_count
 *
 * @method static Builder|static needsReview()
 * @method static Builder|static status(string $status)
 * @method static Builder|static locale(string $locale)
 *
 * @mixin Builder
 *
 * @package LonelyLights\Prosetta\Models
 */
class Translation extends Model {
    /**
     * Create a new model instance.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = []) {
        parent::__construct($attributes);
        $this->table = config('prosetta.tableNames.translations', 'prosetta_translations');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key_id',
        'locale',
        'value',
        'status',
        'source',
        'confidence',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Status constants.
     *
     * @api
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Source constants.
     *
     * @api
     */
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI = 'ai';
    public const SOURCE_IMPORTED = 'imported';

    /**
     * Get the translation key this belongs to.
     *
     * @return BelongsTo
     */
    public function key(): BelongsTo {
        return $this->belongsTo(TranslationKey::class, 'key_id');
    }

    /**
     * Get the review history for this translation.
     *
     * @return HasMany
     */
    public function reviews(): HasMany {
        return $this->hasMany(TranslationReview::class, 'translation_id');
    }

    /**
     * Scope to filter by status.
     *
     * @api
     * @param Builder $query
     * @param string $status
     * @return Builder
     */
    public function scopeStatus(Builder $query, string $status): Builder {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by source.
     *
     * @api
     * @param Builder $query
     * @param string $source
     * @return Builder
     */
    public function scopeSource(Builder $query, string $source): Builder {
        return $query->where('source', $source);
    }

    /**
     * Scope to filter by locale.
     *
     * @api
     * @param Builder $query
     * @param string $locale
     * @return Builder
     */
    public function scopeLocale(Builder $query, string $locale): Builder {
        return $query->where('locale', $locale);
    }

    /**
     * Scope to get translations needing review.
     *
     * @api
     * @param Builder $query
     * @return Builder
     */
    public function scopeNeedsReview(Builder $query): Builder {
        return $query->where('status', self::STATUS_NEEDS_REVIEW);
    }

    /**
     * Scope to get AI-generated translations.
     *
     * @api
     * @param Builder $query
     * @return Builder
     */
    public function scopeAiGenerated(Builder $query): Builder {
        return $query->where('source', self::SOURCE_AI);
    }

    /**
     * Scope to get translations with low confidence.
     *
     * @api
     * @param Builder $query
     * @param float $threshold
     * @return Builder
     */
    public function scopeLowConfidence(Builder $query, float $threshold = 0.7): Builder {
        return $query->whereNotNull('confidence')
            ->where('confidence', '<', $threshold);
    }

    /**
     * Submit this translation for review.
     *
     * @api
     * @param string|null $reason
     * @return bool
     */
    public function submitForReview(?string $reason = null): bool {
        $updated = $this->update(['status' => self::STATUS_NEEDS_REVIEW]);

        if ($updated) {
            TranslationNeedsReview::dispatch($this, $reason);
        }

        return $updated;
    }

    /**
     * Approve this translation.
     *
     * @param int|null $userId
     * @param string|null $notes
     * @return bool
     */
    public function approve(?int $userId = null, ?string $notes = null): bool {
        $reviewerId = $userId ?? auth()->id();

        $updated = $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        if ($updated) {
            $review = $this->reviews()->create([
                'reviewer_id' => $reviewerId,
                'action' => 'approved',
                'notes' => $notes,
            ]);

            TranslationApproved::dispatch($this, $review, $reviewerId);
        }

        return $updated;
    }

    /**
     * Reject this translation.
     *
     * @param int|null $userId
     * @param string|null $notes
     * @return bool
     */
    public function reject(?int $userId = null, ?string $notes = null): bool {
        $reviewerId = $userId ?? auth()->id();

        $updated = $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        if ($updated) {
            $review = $this->reviews()->create([
                'reviewer_id' => $reviewerId,
                'action' => 'rejected',
                'notes' => $notes,
            ]);

            TranslationRejected::dispatch($this, $review, $reviewerId);
        }

        return $updated;
    }

    /**
     * Edit this translation.
     *
     * @param string $newValue
     * @param int|null $userId
     * @param string|null $notes
     * @return bool
     */
    public function edit(string $newValue, ?int $userId = null, ?string $notes = null): bool {
        $previousValue = $this->value;
        $reviewerId = $userId ?? auth()->id();

        $updated = $this->update([
            'value' => $newValue,
            'status' => self::STATUS_APPROVED,
            'source' => self::SOURCE_MANUAL,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        if ($updated) {
            $this->reviews()->create([
                'reviewer_id' => $reviewerId,
                'action' => 'edited',
                'previous_value' => $previousValue,
                'new_value' => $newValue,
                'notes' => $notes,
            ]);

            TranslationUpdated::dispatch($this, $previousValue, $newValue);
        }

        return $updated;
    }

    /**
     * Check if this is an AI-generated translation.
     *
     * @api
     * @return bool
     */
    public function isAiGenerated(): bool {
        return $this->source === self::SOURCE_AI;
    }

    /**
     * Check if this translation needs review.
     *
     * @api
     * @return bool
     */
    public function needsReview(): bool {
        return $this->status === self::STATUS_NEEDS_REVIEW;
    }

    /**
     * Check if this translation is approved.
     *
     * @api
     * @return bool
     */
    public function isApproved(): bool {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Get confidence level as a descriptive string.
     *
     * @api
     * @return string|null
     */
    public function getConfidenceLevel(): ?string {
        if ($this->confidence === null) {
            return null;
        }

        return match (true) {
            $this->confidence >= 0.9 => 'high',
            $this->confidence >= 0.7 => 'medium',
            default => 'low',
        };
    }
}
