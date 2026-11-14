<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property string $title
 * @property string|null $moderator_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MemberTitleModel extends Model
{
    use StoresInstantsInStorageTimezone;

    protected $table = 'forum_member_titles';

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'title', 'moderator_id', 'created_at', 'updated_at'];
}
