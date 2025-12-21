<?php

namespace LonelyLights\Prosetta\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Translation Review Model
 *
 * Audit trail for translation reviews and edits.
 *
 * @property int $id
 * @property int $translation_id           Foreign key to translation
 * @property int|null $reviewer_id         User ID who performed the action
 * @property string $action                Action taken (approved, rejected, edited)
 * @property string|null $previous_value   Value before edit
 * @property string|null $new_value        Value after edit
 * @property string|null $notes            Reviewer comments
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Translation $translation
 *
 * @package LonelyLights\Prosetta\Models
 */
class TranslationReview extends Model
{
    /**
     * Create a new model instance.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('prosetta.tableNames.reviews', 'prosetta_reviews');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'translation_id',
        'reviewer_id',
        'action',
        'previous_value',
        'new_value',
        'notes',
    ];

    /**
     * Action constants.
     *
     * @api
     */
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_EDITED = 'edited';

    /**
     * Get the translation this review belongs to.
     *
     * @return BelongsTo
     */
    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class, 'translation_id');
    }

    /**
     * Scope to filter by action type.
     *
     * @api
     * @param Builder $query
     * @param string $action
     * @return Builder
     */
    public function scopeAction(Builder $query, string $action): Builder {
        return $query->where('action', $action);
    }

    /**
     * Scope to filter by reviewer.
     *
     * @api
     * @param Builder $query
     * @param int $reviewerId
     * @return Builder
     */
    public function scopeByReviewer(Builder $query, int $reviewerId): Builder {
        return $query->where('reviewer_id', $reviewerId);
    }

    /**
     * Scope to get recent reviews.
     *
     * @api
     * @param Builder $query
     * @param int $days
     * @return Builder
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Check if this was an edit action.
     *
     * @api
     * @return bool
     */
    public function isEdit(): bool
    {
        return $this->action === self::ACTION_EDITED;
    }

    /**
     * Check if this was an approval action.
     *
     * @api
     * @return bool
     */
    public function isApproval(): bool
    {
        return $this->action === self::ACTION_APPROVED;
    }

    /**
     * Check if this was a rejection action.
     *
     * @api
     * @return bool
     */
    public function isRejection(): bool
    {
        return $this->action === self::ACTION_REJECTED;
    }

    /**
     * Get a human-readable description of the action.
     *
     * @api
     * @return string
     */
    public function getActionDescription(): string
    {
        return match ($this->action) {
            self::ACTION_APPROVED => 'approved the translation',
            self::ACTION_REJECTED => 'rejected the translation',
            self::ACTION_EDITED => 'edited the translation',
            default => 'performed an action on the translation',
        };
    }
}
