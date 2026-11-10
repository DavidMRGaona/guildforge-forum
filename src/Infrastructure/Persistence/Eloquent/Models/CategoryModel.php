<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $position
 * @property int $discussion_count
 * @property int $post_count
 * @property string|null $last_post_id
 * @property CarbonImmutable|null $last_activity_at
 * @property string|null $color
 * @property string|null $icon
 * @property string|null $system_key
 * @property array<mixed>|null $view_rule
 * @property array<mixed>|null $write_rule
 * @property array<mixed>|null $moderators
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CategoryModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'forum_categories';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Counters are not fillable: only ForumCountersInterface writes them.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id', 'parent_id', 'name', 'slug', 'description', 'position', 'color', 'icon', 'system_key',
        'view_rule', 'write_rule', 'moderators', 'subject_type', 'subject_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'discussion_count' => 'integer',
            'post_count' => 'integer',
            'last_activity_at' => 'immutable_datetime',
            'view_rule' => 'array',
            'write_rule' => 'array',
            'moderators' => 'array',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<DiscussionModel, $this>
     */
    public function discussions(): HasMany
    {
        return $this->hasMany(DiscussionModel::class, 'category_id');
    }
}
