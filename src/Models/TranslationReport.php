<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LonelyLights\Prosetta\Support\Settings;

/**
 * A member's report that a translation reads wrong: open until the string
 * is next approved (accepted) or rejected (dismissed), or staff dismiss it.
 *
 * @property int $id
 * @property int|null $key_id
 * @property string $locale
 * @property string $selected_text
 * @property string|null $suggestion
 * @property string|null $notes
 * @property string $reporter_id
 * @property string|null $url
 * @property string $status open, accepted or dismissed
 * @property bool $queued whether it reached the review queue; if not, staff see it in a list
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property-read TranslationKey|null $key
 */
class TranslationReport extends Model {
    public const string OPEN = 'open';

    public const string ACCEPTED = 'accepted';

    public const string DISMISSED = 'dismissed';

    protected $fillable = ['key_id', 'locale', 'selected_text', 'suggestion', 'notes', 'reporter_id', 'url', 'status', 'queued', 'resolved_by', 'resolved_at'];

    protected $casts = ['resolved_at' => 'datetime', 'queued' => 'boolean'];

    protected $attributes = ['status' => self::OPEN];

    public function getTable(): string {
        return Settings::table('reports');
    }

    /** @return BelongsTo<TranslationKey, $this> */
    public function key(): BelongsTo {
        return $this->belongsTo(Settings::model('key'), 'key_id');
    }
}
