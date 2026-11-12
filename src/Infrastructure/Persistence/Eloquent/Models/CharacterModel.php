<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $owner_id
 * @property string $name
 * @property string|null $description
 * @property string|null $portrait_path
 * @property string|null $category_id
 * @property CarbonImmutable|null $retired_at
 * @property bool $retirement_locked
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CharacterModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'forum_characters';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'owner_id', 'name', 'description', 'portrait_path', 'category_id', 'retired_at', 'retirement_locked', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retired_at' => 'immutable_datetime',
            'retirement_locked' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
