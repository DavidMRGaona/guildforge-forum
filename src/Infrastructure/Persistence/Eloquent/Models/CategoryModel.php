<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
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
    protected $fillable = ['id', 'parent_id', 'name', 'slug', 'description', 'position'];

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
        ];
    }
}
