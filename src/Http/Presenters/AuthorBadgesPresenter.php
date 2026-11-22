<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use App\Application\Profiles\DTOs\ProfileBadgeDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Application\Services\PostAuthorBadges;
use Modules\Forum\Domain\Entities\Post;

/**
 * Adds `authorBadges` to each post's props (forum#152): the featured badges `ProfileBadges.vue`
 * draws next to the author, an empty list where the post shows none. One call per answer, for
 * the discussion page, its live updates and the conversation of a record's page alike.
 */
final readonly class AuthorBadgesPresenter
{
    public function __construct(
        private PostAuthorBadges $badges,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $props  as ForumPresenter::posts() built them, in the order of the posts
     * @param  list<Post>  $posts
     * @param  list<PostContentDTO>  $contents  what each post shows to the reader, in the same order
     * @return list<array<string, mixed>>
     */
    public function withBadges(array $props, array $posts, array $contents, ?Authenticatable $reader): array
    {
        $badges = $this->badges->forPosts($posts, $contents, $reader);

        foreach (array_keys($props) as $index) {
            $props[$index]['authorBadges'] = array_map(
                static fn (ProfileBadgeDTO $badge): array => $badge->toArray(),
                $badges[$index] ?? [],
            );
        }

        return $props;
    }
}
