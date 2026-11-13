<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Realtime;

/**
 * The forum's real-time channels and what travels on them (core#493). Only ids and numbers ever
 * travel: a message says that something happened, and the page asks for it through its usual
 * request, which checks the access rules again.
 *
 * - A discussion: `post.created` {number} (a post got its number), `post.changed` {number} (an
 *   edit, a deletion, moderation, a like, a revealed roll) and `discussion.changed` (closed,
 *   renamed, moved, hidden, merged, split or deleted: the page catches up). Its readers also
 *   whisper `typing` {user} among themselves, which never goes through the application.
 * - A category: `discussion.activity` {discussion, number}, a post published in a discussion of
 *   it that anyone reading the category may see.
 * - A private conversation (forum#89): `entry.created` {number} (a message, or the note of
 *   someone added or gone) and `entry.changed` {number} (deleted by its author, hidden by
 *   moderation). Only the people in it listen.
 * - An account: `pulse.changed`, something changed what the forum's menu shows that person.
 */
final class ForumChannels
{
    public const string DISCUSSION = 'forum.discussion.{discussion}';

    public const string CATEGORY = 'forum.category.{category}';

    public const string ACCOUNT = 'forum.account.{account}';

    public const string CONVERSATION = 'forum.conversation.{conversation}';

    public const string ENTRY_CREATED = 'entry.created';

    public const string ENTRY_CHANGED = 'entry.changed';

    public const string POST_CREATED = 'post.created';

    public const string POST_CHANGED = 'post.changed';

    public const string DISCUSSION_CHANGED = 'discussion.changed';

    public const string DISCUSSION_ACTIVITY = 'discussion.activity';

    public const string PULSE_CHANGED = 'pulse.changed';

    public static function discussion(string $discussionId): string
    {
        return 'forum.discussion.'.strtolower($discussionId);
    }

    public static function category(string $categoryId): string
    {
        return 'forum.category.'.strtolower($categoryId);
    }

    public static function conversation(string $conversationId): string
    {
        return 'forum.conversation.'.strtolower($conversationId);
    }

    public static function account(string $accountId): string
    {
        return 'forum.account.'.strtolower($accountId);
    }
}
