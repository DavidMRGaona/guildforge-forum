<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Repositories\ModerationLogRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;

final class EloquentModerationLogRepository implements ModerationLogRepositoryInterface
{
    public function record(ModerationLogEntry $entry): void
    {
        ModerationLogModel::query()->create([
            'id' => $entry->id,
            'action' => $entry->action->value,
            'moderator_id' => $entry->moderatorId,
            'subject_user_id' => $entry->subjectUserId,
            'category_id' => $entry->categoryId,
            'discussion_id' => $entry->discussionId,
            'post_id' => $entry->postId,
            'reason' => $entry->reason,
            'details' => $entry->details === [] ? null : $entry->details,
            'created_at' => $entry->at,
        ]);
    }
}
