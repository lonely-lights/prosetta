<?php

namespace LonelyLights\Prosetta\Models;

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
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
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
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $action
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to filter by reviewer.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $reviewerId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByReviewer($query, int $reviewerId)
    {
        return $query->where('reviewer_id', $reviewerId);
    }

    /**
     * Scope to get recent reviews.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Check if this was an edit action.
     *
     * @return bool
     */
    public function isEdit(): bool
    {
        return $this->action === self::ACTION_EDITED;
    }

    /**
     * Check if this was an approval action.
     *
     * @return bool
     */
    public function isApproval(): bool
    {
        return $this->action === self::ACTION_APPROVED;
    }

    /**
     * Check if this was a rejection action.
     *
     * @return bool
     */
    public function isRejection(): bool
    {
        return $this->action === self::ACTION_REJECTED;
    }

    /**
     * Get a human-readable description of the action.
     *
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
