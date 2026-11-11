<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $post_id
 * @property string|null $reporter_id
 * @property string $reason
 * @property string|null $note
 * @property string|null $resolution
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 */
final class ReportModel extends Model
{
    use StoresInstantsInStorageTimezone;

    public const null UPDATED_AT = null;

    protected $table = 'forum_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'post_id', 'reporter_id', 'reason', 'note', 'resolution', 'resolved_by', 'resolved_at', 'created_at'];

    /**
     * @return BelongsTo<PostModel, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(PostModel::class, 'post_id');
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'reporter_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
