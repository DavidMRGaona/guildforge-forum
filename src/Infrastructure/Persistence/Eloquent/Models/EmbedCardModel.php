<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $service
 * @property string $embed_id
 * @property bool $available
 * @property string|null $title
 * @property string|null $author_name
 * @property string|null $thumbnail_path
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable $fetched_at
 */
final class EmbedCardModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public $timestamps = false;

    protected $table = 'forum_embeds';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['service', 'embed_id', 'available', 'title', 'author_name', 'thumbnail_path', 'details', 'fetched_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'details' => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
