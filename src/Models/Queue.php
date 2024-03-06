<?php

namespace Prosetta\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Queue Model
 *
 * @property int $id
 * @property string $key
 * @property string $path
 * @property string $lang
 * @property string $method
 * @property string $model
 * @property string|null $model_description
 * @property string|null $context
 * @property string|null $origin_lang
 * @property string|null $origin_value
 * @property array|null $attributes
 * @property int $creator_id
 * @property int|null $rank
 * @property-read User $creator
 *
 * @method static Builder|Queue where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static Builder|Queue create(array $attributes = [])
 * @method static Builder|Queue|null find(mixed $id, array $columns = ['*']) */
class Queue extends Model {

    ###########################################################
    # Model Configuration
    ###########################################################

    # Data Mapping
    protected $table = 'prosetta_queue';

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'attributes' => 'array',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'key',
        'path',
        'lang',
        'method',
        'model',
        'model_description',
        'context',
        'base_lang',
        'original_value',
        'base_original_value',
        'base_value',
        'attributes',
        'creator_id',
        'rank'
    ];

    ###########################################################
    # Relationships
    ###########################################################

    # Creator
    public function creator(): BelongsTo {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
