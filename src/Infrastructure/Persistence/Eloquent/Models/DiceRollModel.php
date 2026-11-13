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
 * @property int $position
 * @property string $expression
 * @property int $dice_count
 * @property array<string, mixed>|null $result
 * @property int|null $total
 * @property string|null $error
 * @property bool $added_on_edit
 * @property CarbonImmutable $rolled_at
 * @property CarbonImmutable|null $removed_at
 * @property bool $is_hidden
 * @property string|null $rolled_by
 * @property CarbonImmutable|null $revealed_at
 * @property string|null $revealed_by
 */
final class DiceRollModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    public $timestamps = false;

    protected $table = 'forum_dice_rolls';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['post_id', 'position', 'expression', 'dice_count', 'result', 'total', 'error', 'added_on_edit', 'rolled_at', 'removed_at', 'is_hidden', 'rolled_by', 'revealed_at', 'revealed_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'dice_count' => 'integer',
            'result' => 'array',
            'total' => 'integer',
            'added_on_edit' => 'boolean',
            'rolled_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'is_hidden' => 'boolean',
            'revealed_at' => 'immutable_datetime',
        ];
    }
}
