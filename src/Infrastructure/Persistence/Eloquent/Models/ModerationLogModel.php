<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of the moderation log, read by the panel. Rows are written only through
 * ModerationLogRepositoryInterface and never changed.
 *
 * @property string $id
 * @property string $action
 * @property string|null $moderator_id
 * @property string|null $subject_user_id
 * @property string|null $category_id
 * @property string|null $discussion_id
 * @property string|null $post_id
 * @property string|null $reason
 * @property array<string, string|int|bool|null>|null $details
 * @property CarbonImmutable $created_at
 */
final class ModerationLogModel extends Model
{
    use StoresInstantsInStorageTimezone;

    public const null UPDATED_AT = null;

    protected $table = 'forum_moderation_log';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'action', 'moderator_id', 'subject_user_id', 'category_id', 'discussion_id', 'post_id', 'reason', 'details', 'created_at'];

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'moderator_id');
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'subject_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
