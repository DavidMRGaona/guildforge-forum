<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\AccountEngagementStoreInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;

final readonly class EloquentAccountEngagementStore implements AccountEngagementStoreInterface
{
    /** Table and column of each row that belongs to the person's own choices */
    private const array OWNED = [
        ['forum_post_likes', 'user_id'],
        ['forum_discussion_follows', 'user_id'],
        ['forum_category_follows', 'user_id'],
        ['forum_user_preferences', 'user_id'],
        ['forum_discussion_reads', 'user_id'],
        ['forum_category_reads', 'user_id'],
        ['forum_reports', 'reporter_id'],
        // Private conversations: what the person blocked or was blocked by, their starts and
        // the reports they made (AccountErasure takes them out of their conversations first)
        ['forum_user_blocks', 'blocker_user_id'],
        ['forum_user_blocks', 'blocked_user_id'],
        ['forum_conversation_starts', 'user_id'],
        ['forum_conversation_reports', 'reporter_id'],
    ];

    public function forget(string $accountId): void
    {
        $id = AccountId::normalize($accountId);
        if ($id === null) {
            return;
        }

        DB::transaction(static function () use ($id): void {
            foreach (self::OWNED as [$table, $column]) {
                DB::table($table)->where($column, $id)->delete();
            }
        });
    }
}
