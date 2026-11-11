<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $discussion_id
 * @property int|null $number
 * @property string|null $author_id
 * @property string $status
 * @property array<string, mixed> $body
 * @property string $plain_text
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $content_purged_at
 * @property string|null $hidden_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PostModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'forum_posts';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'discussion_id', 'number', 'author_id', 'status', 'body', 'plain_text', 'published_at', 'edited_at', 'deleted_at', 'content_purged_at', 'hidden_reason', 'created_at'];

    /**
     * @return BelongsTo<DiscussionModel, $this>
     */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(DiscussionModel::class, 'discussion_id');
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'author_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'body' => 'array',
            'published_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'content_purged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
