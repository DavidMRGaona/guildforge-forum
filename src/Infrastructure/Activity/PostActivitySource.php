<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Activity;

use App\Application\Activity\DTOs\ActivitySubjectDTO;
use App\Domain\Activity\ActivityKind;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Activity\ActivityCategories;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Queries\ActivitySubjectsQueryInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

/**
 * `forum.post` (forum#151): each post that counts, for whoever wrote it. The opening post of a
 * discussion is a post like any other; opening it counts apart (`forum.discussion`). A post written
 * as one of the writer's own characters counts for them, as the forum names them; one as a shared
 * character never does, since it hides its writer (ActivitySubjectsQueryInterface has the rest).
 */
final readonly class PostActivitySource extends ForumActivitySource
{
    public const string TYPE = 'forum.post';

    public function __construct(
        ActivityCategories $categories,
        private ActivitySubjectsQueryInterface $query,
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
        private ForumUrlsInterface $urls,
    ) {
        parent::__construct($categories);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function kind(): ActivityKind
    {
        return ActivityKind::ForumPost;
    }

    public function label(): string
    {
        $text = __('forum::messages.activity.posts');

        return is_string($text) ? $text : self::TYPE;
    }

    /**
     * Visible posts of discussions the viewer may read, with that discussion's title and the
     * post's own address.
     */
    public function describe(array $subjectIds, ?Authenticatable $viewer): array
    {
        $ids = self::wellFormed($subjectIds);
        if ($ids === []) {
            return [];
        }

        /** @var array<string, Discussion|null> $discussions */
        $discussions = [];
        $described = [];
        foreach ($this->posts->findMany(array_map(PostId::fromString(...), $ids)) as $post) {
            $number = $post->number();
            if (! $post->isVisible() || ! $number instanceof PostNumber) {
                continue;
            }

            $discussionId = $post->discussionId();
            if (! array_key_exists($discussionId->value, $discussions)) {
                $discussion = $this->discussions->find($discussionId);
                $discussions[$discussionId->value] = $discussion instanceof Discussion && $this->access->canRead($viewer, $discussion) ? $discussion : null;
            }

            $discussion = $discussions[$discussionId->value];
            if ($discussion instanceof Discussion) {
                $described[$post->id()->value] = $this->subject($post, $number, $discussion);
            }
        }

        return $described;
    }

    protected function rows(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array
    {
        return $this->query->posts($categoryIds, $onlyIds, $afterId, $limit);
    }

    private function subject(Post $post, PostNumber $number, Discussion $discussion): ActivitySubjectDTO
    {
        $title = __('forum::messages.activity.post_title', ['number' => $number->value, 'title' => $discussion->title()->value]);

        return new ActivitySubjectDTO(
            $post->id()->value,
            is_string($title) ? $title : $discussion->title()->value,
            $this->urls->discussion($discussion, $number->value),
        );
    }
}
