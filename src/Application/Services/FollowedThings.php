<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * What a person follows, for their preferences: only what they may still read.
 */
final readonly class FollowedThings
{
    /** How many followed discussions the preferences list */
    public const int DISCUSSIONS = 50;

    public function __construct(
        private FollowsInterface $follows,
        private ForumBrowser $browser,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
    ) {}

    /**
     * @return list<CategoryAccessDTO> in the forum's order
     */
    public function categories(Authenticatable $user): array
    {
        $followed = array_flip($this->follows->followedCategoryIds((string) ForumBrowser::accountId($user)));

        return array_values(array_filter(
            $this->browser->readableCategories($user),
            static fn (CategoryAccessDTO $access): bool => isset($followed[$access->category->id()->value]),
        ));
    }

    /**
     * @return list<Discussion> most recently followed first
     */
    public function discussions(Authenticatable $user): array
    {
        $found = [];
        foreach ($this->follows->followedDiscussionIds((string) ForumBrowser::accountId($user), self::DISCUSSIONS) as $id) {
            $discussion = $this->discussions->find(DiscussionId::fromString($id));
            if ($discussion instanceof Discussion && $this->access->canRead($user, $discussion)) {
                $found[] = $discussion;
            }
        }

        return $found;
    }
}
