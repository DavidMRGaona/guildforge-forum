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
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * `forum.discussion` (forum#151): each discussion that counts, for whoever opened it. A shared
 * character never opens one, so its author is always known (ActivitySubjectsQueryInterface has
 * the rest).
 */
final readonly class DiscussionActivitySource extends ForumActivitySource
{
    public const string TYPE = 'forum.discussion';

    public function __construct(
        ActivityCategories $categories,
        private ActivitySubjectsQueryInterface $query,
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
        return ActivityKind::ForumDiscussion;
    }

    public function label(): string
    {
        $text = __('forum::messages.activity.discussions');

        return is_string($text) ? $text : self::TYPE;
    }

    /**
     * Numbered discussions the viewer may read, with their title and address.
     */
    public function describe(array $subjectIds, ?Authenticatable $viewer): array
    {
        $described = [];
        foreach (self::wellFormed($subjectIds) as $id) {
            $discussion = $this->discussions->find(DiscussionId::fromString($id));
            if ($discussion instanceof Discussion && $discussion->number() !== null && $this->access->canRead($viewer, $discussion)) {
                $described[$id] = new ActivitySubjectDTO($id, $discussion->title()->value, $this->urls->discussion($discussion));
            }
        }

        return $described;
    }

    protected function rows(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array
    {
        return $this->query->discussions($categoryIds, $onlyIds, $afterId, $limit);
    }
}
