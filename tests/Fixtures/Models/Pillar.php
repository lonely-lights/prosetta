<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use LonelyLights\Prosetta\Content\TranslatesContent;

/**
 * @property string $slug
 * @property string $name
 * @property string|null $badge
 * @property bool $draft
 */
final class Pillar extends Model {
    use TranslatesContent;

    protected $table = 'pillars';
    protected $guarded = [];
    protected $casts = ['draft' => 'boolean'];

    public function getRouteKeyName(): string {
        return 'slug';
    }

    public function translatableFields(): array {
        return [
            'name' => 'The full name of a pillar.',
            'badge' => 'A one- or two-word short name.',
        ];
    }

    public function translationMaxLength(string $field): ?int {
        return $field === 'badge' ? 20 : null;
    }

    public function shouldTranslate(): bool {
        return ! $this->draft;
    }
}
