<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LonelyLights\Prosetta\Enums\FileFormat;
use LonelyLights\Prosetta\Support\Settings;

/**
 * @property int $id
 * @property string $namespace
 * @property string $group
 * @property FileFormat $format
 */
class TranslationFile extends Model {
    protected $fillable = ['namespace', 'group', 'format'];

    protected $casts = ['format' => FileFormat::class];

    public function getTable(): string {
        return Settings::table('files');
    }

    public function keys(): HasMany {
        return $this->hasMany(Settings::model('key'), 'file_id');
    }
}
