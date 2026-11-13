<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $conversation_id
 * @property int $number
 * @property string $kind
 * @property string|null $author_id
 * @property string|null $subject_user_id
 * @property array<string, mixed>|null $body
 * @property string|null $plain_text
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $hidden_at
 * @property string|null $hidden_reason
 */
final class ConversationMessageModel extends Model
{
    use StoresInstantsInStorageTimezone;

    public $timestamps = false;

    protected $table = 'forum_conversation_messages';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'conversation_id', 'number', 'kind', 'author_id', 'subject_user_id', 'body', 'plain_text', 'created_at', 'deleted_at', 'hidden_at', 'hidden_reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'body' => 'array',
            'created_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'hidden_at' => 'immutable_datetime',
        ];
    }
}
