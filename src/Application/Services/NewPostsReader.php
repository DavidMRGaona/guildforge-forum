<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\NewPostsDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

/**
 * What an open discussion asks every few seconds: the numbered posts after the last one the
 * page has. It checks the cheap thing first (the highest number, one read of an index), so
 * «nothing new» costs one read. Posts awaiting approval have no number and never come this way,
 * and the read marks are not touched: only the page reports what the reader actually saw. A
 * page told in real time that some of its posts changed asks for them here too.
 */
final readonly class NewPostsReader
{
    /** Posts one answer carries; the page asks again for the rest */
    public const int MAX = 50;

    public function __construct(
        private DiscussionRepositoryInterface $discussions,
        private PostRepositoryInterface $posts,
        private ForumAccessInterface $access,
        private PostContentPresenter $presenter,
    ) {}

    /**
     * @param  list<int>  $changed  numbers of posts the page holds and was told changed (an edit,
     *                              moderation, a like, a revealed roll): they come again as the
     *                              reader sees them now, drawn by the same rules as the page
     * @return NewPostsDTO|null null when nothing came after the number and none of the changed is there
     *
     * @throws DiscussionNotFoundException when the reader may not read the discussion
     */
    public function after(?Authenticatable $reader, DiscussionId $id, int $number, array $changed = []): ?NewPostsDTO
    {
        $discussion = $this->discussions->find($id);
        if (! $discussion instanceof Discussion || ! $this->access->canRead($reader, $discussion)) {
            throw DiscussionNotFoundException::withId($id->value);
        }

        $highest = $this->posts->highestNumber($id)->value ?? 0;
        $wanted = array_values(array_unique(array_filter($changed, static fn (int $changedNumber): bool => $changedNumber <= $number)));
        if ($highest <= $number && $wanted === []) {
            return null;
        }

        $fetched = $highest > $number ? $this->posts->numberedFrom($id, new PostNumber($number + 1), self::MAX + 1) : [];
        $posts = [...$this->posts->withNumbers($id, $wanted), ...array_slice($fetched, 0, self::MAX)];
        if ($posts === []) {
            return null;
        }

        usort($posts, static fn (Post $a, Post $b): int => ($a->number()->value ?? 0) <=> ($b->number()->value ?? 0));

        return new NewPostsDTO(
            discussion: $discussion,
            posts: $posts,
            contents: $this->presenter->present($posts, $discussion, $reader),
            highestNumber: $highest,
            more: count($fetched) > self::MAX,
            canModerate: $this->access->canModerate($reader, $discussion),
        );
    }
}
