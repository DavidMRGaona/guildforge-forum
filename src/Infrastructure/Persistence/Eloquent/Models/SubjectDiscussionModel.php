<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Keyed by the publication's type and id (both columns): saving matches on both.
 *
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $discussion_id
 * @property string|null $first_post_id
 * @property array<string, mixed>|null $facts
 * @property bool $is_live
 * @property CarbonImmutable|null $next_check_at
 * @property bool|null $space_open
 */
final class SubjectDiscussionModel extends Model
{
    use StoresInstantsInStorageTimezone;

    protected $table = 'forum_subject_discussions';

    protected $primaryKey = 'subject_id';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['subject_type', 'subject_id', 'discussion_id', 'first_post_id', 'facts', 'is_live', 'next_check_at', 'space_open'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'is_live' => 'boolean',
            'next_check_at' => 'immutable_datetime',
            'space_open' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query
            ->where('subject_type', $this->getOriginal('subject_type') ?? $this->getAttribute('subject_type'))
            ->where('subject_id', $this->getOriginal('subject_id') ?? $this->getAttribute('subject_id'));
    }
}
