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
 * @property string|null $message_id
 * @property string|null $message_author_id
 * @property string|null $reporter_id
 * @property string $reason
 * @property string|null $note
 * @property list<array<string, mixed>> $context
 * @property string|null $resolution
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 */
final class ConversationReportModel extends Model
{
    use StoresInstantsInStorageTimezone;

    public $timestamps = false;

    protected $table = 'forum_conversation_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'message_id', 'message_author_id', 'reporter_id', 'reason', 'note', 'context', 'resolution', 'resolved_by', 'resolved_at', 'created_at'];

    /**
     * Whoever wrote the reported message.
     *
     * @return BelongsTo<UserModel, $this>
     */
    public function messageAuthor(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'message_author_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
