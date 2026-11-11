<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\DiscussionSlug;
use RuntimeException;

/**
 * The forum's addresses through the module's named routes.
 */
final readonly class RouteForumUrls implements ForumUrlsInterface
{
    public function discussion(Discussion $discussion, ?int $postNumber = null, bool $absolute = false): string
    {
        $number = $discussion->number() ?? throw new RuntimeException("Discussion {$discussion->id()->value} has no number yet.");

        return $this->discussionFrom($number, $discussion->title()->value, $postNumber, $absolute);
    }

    public function discussionFrom(int $number, string $title, ?int $postNumber = null, bool $absolute = false): string
    {
        $parameters = ['discussion' => DiscussionSlug::segment($number, $title)];
        if ($postNumber !== null && $postNumber > 1) {
            $parameters['post'] = $postNumber;
        }

        return route('forum.discussions.show', $parameters, $absolute);
    }

    public function category(Category $category, bool $absolute = false): string
    {
        return route('forum.categories.show', ['category' => $category->slug()->value], $absolute);
    }

    public function index(bool $absolute = false): string
    {
        return route('forum.index', [], $absolute);
    }
}
