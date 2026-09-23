<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LonelyLights\Prosetta\Enums\ReviewAction;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property int $translation_id
 * @property string|null $reviewer_id
 * @property ReviewAction $action
 * @property string|null $previous_value
 * @property string|null $new_value
 * @property string|null $notes
 */
class TranslationReview extends Model {
    protected $fillable = ['translation_id', 'reviewer_id', 'action', 'previous_value', 'new_value', 'notes'];

    protected $casts = ['action' => ReviewAction::class];

    public function getTable(): string {
        return Settings::table('reviews');
    }

    /** @return BelongsTo<Translation, $this> */
    public function translation(): BelongsTo {
        return $this->belongsTo(Settings::model('translation'), 'translation_id');
    }
}
