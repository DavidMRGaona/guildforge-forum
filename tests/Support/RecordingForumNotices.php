<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use Modules\Forum\Application\Contracts\ForumNoticesInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Remembers what the forum asked to announce, without queueing anything.
 */
final class RecordingForumNotices implements ForumNoticesInterface
{
    /** @var list<string> */
    public array $published = [];

    /** @var list<array{string, PostBody}> */
    public array $edited = [];

    /** @var list<array{string, string}> */
    public array $liked = [];

    /** @var list<string> */
    public array $forgotten = [];

    /** @var list<array{string, string}> */
    public array $addedToSpace = [];

    public function postPublished(PostId $postId): void
    {
        $this->published[] = $postId->value;
    }

    public function postEdited(PostId $postId, PostBody $before): void
    {
        $this->edited[] = [$postId->value, $before];
    }

    public function postLiked(PostId $postId, string $likerId): void
    {
        $this->liked[] = [$postId->value, $likerId];
    }

    public function addedToSpace(CategoryId $spaceId, string $accountId): void
    {
        $this->addedToSpace[] = [$spaceId->value, $accountId];
    }

    public function forgetDiscussion(DiscussionId $discussionId): void
    {
        $this->forgotten[] = 'discussion:'.$discussionId->value;
    }

    public function forgetCategory(CategoryId $categoryId): void
    {
        $this->forgotten[] = 'category:'.$categoryId->value;
    }
}
