<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $url_hash
 * @property string $url
 * @property string $path
 * @property CarbonImmutable $fetched_at
 */
final class ExternalImageModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public $timestamps = false;

    protected $table = 'forum_external_images';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['url_hash', 'url', 'path', 'fetched_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fetched_at' => 'immutable_datetime'];
    }
}
