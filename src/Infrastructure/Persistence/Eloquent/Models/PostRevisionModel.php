<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $post_id
 * @property int $number
 * @property array<string, mixed> $body
 * @property string $plain_text
 * @property string|null $editor_id
 * @property string|null $character_id
 * @property CarbonImmutable $created_at
 */
final class PostRevisionModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public const null UPDATED_AT = null;

    protected $table = 'forum_post_revisions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['post_id', 'number', 'body', 'plain_text', 'editor_id', 'character_id', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'body' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
