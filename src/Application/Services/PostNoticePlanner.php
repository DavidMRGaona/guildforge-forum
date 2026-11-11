<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Application\Content\DocumentReferences;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostNoticeKind;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Who hears about a published post, and why:
 * - the accounts it mentions, and the authors of the posts it quotes;
 * - a reply: whoever follows the discussion;
 * - a new discussion: whoever follows its category or the category above it.
 * For an edit, only who it now mentions or quotes and did not before. Never its author, nor
 * accounts that cannot be reached (deactivated, anonymized or the system account). Whether each
 * may still read it is checked when each notice is delivered.
 */
final readonly class PostNoticePlanner
{
    public function __construct(
        private FollowsInterface $follows,
        private PostRepositoryInterface $posts,
        private CategoryRepositoryInterface $categories,
        private AccountDirectoryInterface $accounts,
    ) {}

    /**
     * @param  DocumentReferences|null  $before  what the post referred to before an edit; null for a new post
     * @return array<string, PostNoticeKind> by account id
     */
    public function plan(Post $post, Discussion $discussion, ?DocumentReferences $before = null): array
    {
        $references = DocumentReferences::of($post->body());

        $mentioned = $references->mentionedAccountIds;
        $quotedPostIds = $references->quotedPostIds;
        if ($before instanceof DocumentReferences) {
            $mentioned = array_values(array_diff($mentioned, $before->mentionedAccountIds));
            $quotedPostIds = array_values(array_diff($quotedPostIds, $before->quotedPostIds));
        }

        $plan = [];
        foreach ($mentioned as $accountId) {
            $plan[$accountId] ??= PostNoticeKind::Mention;
        }

        foreach ($this->posts->findMany(array_map(PostId::fromString(...), $quotedPostIds)) as $quoted) {
            $authorId = $quoted->authorId();
            if ($authorId !== null) {
                $plan[$authorId] ??= PostNoticeKind::Quote;
            }
        }

        if (! $before instanceof DocumentReferences) {
            $isFirst = $post->number()?->value === 1;
            $followers = $isFirst
                ? $this->follows->categoryFollowers($this->categoryAndParent($discussion->categoryId()))
                : $this->follows->discussionFollowers($discussion->id());

            foreach ($followers as $accountId) {
                $plan[$accountId] ??= $isFirst ? PostNoticeKind::NewDiscussion : PostNoticeKind::Reply;
            }
        }

        unset($plan[(string) $post->authorId()]);
        if ($plan === []) {
            return [];
        }

        $reachable = array_flip($this->accounts->mentionable(array_map(strval(...), array_keys($plan))));

        return array_filter($plan, static fn (string $accountId): bool => isset($reachable[$accountId]), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return list<CategoryId>
     */
    private function categoryAndParent(CategoryId $categoryId): array
    {
        $category = $this->categories->find($categoryId);
        $parentId = $category instanceof Category ? $category->parentId() : null;

        return $parentId instanceof CategoryId ? [$categoryId, $parentId] : [$categoryId];
    }
}
