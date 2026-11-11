<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Throwable;

/**
 * Whether a post waits for a moderator's approval before anyone else reads it. A category may
 * ask newcomers for it (approval_threshold): someone with fewer posts published in the whole
 * forum than that waits there. Those who moderate the category never wait.
 *
 * It fails closed: when the moderation rules or the count cannot be read, the post waits.
 */
final readonly class ApprovalRule
{
    public function __construct(
        private CategoryAccessPolicy $policy,
        private PostRepositoryInterface $posts,
    ) {}

    public function needsApproval(Authenticatable $author, Category $category): bool
    {
        $threshold = $category->approvalThreshold();
        if ($threshold === 0) {
            return false;
        }

        try {
            if ($this->policy->canModerate($author, $category)) {
                return false;
            }

            $authorId = ForumBrowser::accountId($author);

            return $authorId === null || $this->posts->countPublishedBy($authorId, $threshold) < $threshold;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }
}
