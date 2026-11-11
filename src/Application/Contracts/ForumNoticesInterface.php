<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Who hears about what happens in the forum. Each call only hands the work to the queue: who
 * gets a notice, and whether they may still read it, is decided there and checked again when
 * each notice is delivered.
 */
interface ForumNoticesInterface
{
    /**
     * A post got its number (a reply, a new discussion or a post approved): its mentions, the
     * authors it quotes and the discussion's (or category's) followers hear about it.
     */
    public function postPublished(PostId $postId): void;

    /**
     * A published post was edited: only the people it now mentions or quotes and did not before.
     */
    public function postEdited(PostId $postId, PostBody $before): void;

    /**
     * Someone liked a post: its author hears about it, on the site only.
     */
    public function postLiked(PostId $postId, string $likerId): void;

    /**
     * Someone joined the roster of a campaign whose space takes posts: they hear they can enter
     * it, on the site only.
     */
    public function addedToSpace(CategoryId $spaceId, string $accountId): void;

    /**
     * A discussion was deleted: its mutes and its notices go, for everybody.
     */
    public function forgetDiscussion(DiscussionId $discussionId): void;

    /**
     * A category was deleted: its mutes and its notices go, for everybody.
     */
    public function forgetCategory(CategoryId $categoryId): void;
}
