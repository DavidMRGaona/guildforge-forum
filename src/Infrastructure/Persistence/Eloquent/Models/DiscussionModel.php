<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int|null $number
 * @property string $category_id
 * @property string|null $author_id
 * @property string $title
 * @property string $status
 * @property bool $is_pinned
 * @property bool $is_hidden
 * @property bool $closed_by_system
 * @property int $post_count
 * @property string|null $last_post_id
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class DiscussionModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'forum_discussions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Counters are not fillable: only ForumCountersInterface writes them.
     *
     * @var list<string>
     */
    protected $fillable = ['id', 'number', 'category_id', 'author_id', 'title', 'status', 'is_pinned', 'is_hidden', 'closed_by_system', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_pinned' => 'boolean',
            'is_hidden' => 'boolean',
            'closed_by_system' => 'boolean',
            'post_count' => 'integer',
            'last_activity_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
