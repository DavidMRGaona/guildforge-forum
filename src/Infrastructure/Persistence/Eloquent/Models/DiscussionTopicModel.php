<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $discussion_id
 * @property string $key
 * @property string $label
 */
final class DiscussionTopicModel extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'forum_discussion_topics';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = ['discussion_id', 'key', 'label'];
}
