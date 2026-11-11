<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\DTOs\ConversationPageDTO;
use Modules\Forum\Application\DTOs\PostLikesDTO;
use Modules\Forum\Application\Services\FollowService;
use Modules\Forum\Application\Services\PostLikeService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * Likes and follows on the forum's pages: each post's likes, how the reader follows the
 * discussion and whether they follow a category. Guests see the likes but follow nothing.
 */
final readonly class EngagementPresenter
{
    public function __construct(
        private PostLikeService $likes,
        private FollowService $follows,
    ) {}

    /**
     * Adds `likes` to each post and `follow` to the discussion of the conversation's props.
     *
     * @param  array<string, mixed>  $props  as ForumPresenter::conversation() built them
     * @return array<string, mixed>
     */
    public function conversation(array $props, ConversationPageDTO $page, ?Authenticatable $reader): array
    {
        /** @var list<array<string, mixed>> $posts */
        $posts = is_array($props['posts'] ?? null) ? $props['posts'] : [];
        $props['posts'] = $this->withLikes($posts, $page->posts, $page->discussion, $reader);

        /** @var array<string, mixed> $discussion */
        $discussion = is_array($props['discussion'] ?? null) ? $props['discussion'] : [];
        $discussion['follow'] = $reader instanceof Authenticatable ? [
            'level' => $this->follows->levelOf($reader, $page->discussion)->value,
            'url' => route('forum.discussions.follow', ['discussion' => $page->discussion->id()->value]),
        ] : null;
        $props['discussion'] = $discussion;

        return $props;
    }

    /**
     * Adds `likes` to each post's props (as ForumPresenter::posts() built them, in the order of
     * the posts), for the discussion page and the conversation of a record's page alike.
     *
     * @param  list<array<string, mixed>>  $props
     * @param  list<Post>  $posts
     * @return list<array<string, mixed>>
     */
    public function withLikes(array $props, array $posts, Discussion $discussion, ?Authenticatable $reader): array
    {
        $likes = $this->likes->forPosts($reader, $posts);
        $names = $this->likes->namesOf($likes);

        foreach ($posts as $index => $post) {
            if (! isset($props[$index])) {
                continue;
            }

            $canLike = $this->likes->canLike($reader, $post, $discussion);
            $props[$index]['likes'] = [
                ...ForumPresenter::likes($likes[$post->id()->value] ?? PostLikesDTO::none(), $names),
                'canLike' => $canLike,
                'url' => $canLike || ($likes[$post->id()->value] ?? null)?->likedByViewer === true
                    ? route('forum.posts.like', ['post' => $post->id()->value])
                    : null,
            ];
        }

        return $props;
    }

    /**
     * @return array{following: bool, url: string}|null
     */
    public function categoryFollow(?Authenticatable $reader, Category $category): ?array
    {
        return $reader instanceof Authenticatable ? [
            'following' => $this->follows->followsCategory($reader, $category),
            'url' => route('forum.categories.follow', ['category' => $category->slug()->value]),
        ] : null;
    }
}
