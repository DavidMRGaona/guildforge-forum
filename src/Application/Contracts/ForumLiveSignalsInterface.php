<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * What the forum tells open pages in real time once a write commits (ForumChannels). Only ids and
 * numbers travel; the pages ask for the rest through their usual requests. Without real time it
 * does nothing, and it never throws: a page that misses a message catches up by itself.
 */
interface ForumLiveSignalsInterface
{
    /**
     * A post got its number (a reply, a new discussion, an approval): its discussion, its
     * category when anyone reading it may see the discussion, and whoever follows either.
     */
    public function postPublished(Post $post): void;

    /** A numbered post changed: an edit, a deletion, moderation, a like, a revealed roll */
    public function postChanged(Post $post): void;

    /** The discussion changed as a whole (closed, renamed, moved, hidden, merged, split, deleted) */
    public function discussionChanged(DiscussionId $discussionId): void;

    /**
     * A conversation got an entry (a message, the note of someone added or gone): its open pages,
     * and the menu of everyone in it but whoever wrote it.
     */
    public function conversationEntryAdded(ConversationId $conversationId, int $number, ?string $authorId): void;

    /** An entry of a conversation changed (deleted by its author, hidden by moderation) */
    public function conversationEntryChanged(ConversationId $conversationId, int $number): void;

    /** What the forum's menu counts for the person changed (their reads or follows) */
    public function pulseChanged(string $accountId): void;
}
