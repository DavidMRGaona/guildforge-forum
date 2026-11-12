<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Search;

use App\Application\Search\DTOs\SearchDocumentDTO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Search\ForumSearchAudiences;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

/**
 * Turns discussions and replies into search documents, and decides which of them exist at all.
 *
 * A discussion is searchable when it has its number, is not hidden and does not await approval;
 * a reply when it is visible (not hidden, pending or deleted), numbered and published, and its
 * discussion is searchable. Whatever the category's read rule cannot say as a search audience is
 * left out (see ForumSearchAudiences). Moderation notes, reasons and authors never reach the index.
 */
final class ForumSearchDocuments
{
    /** Posts are cut here so one very long message cannot make the document too big for the engine. */
    private const int MAX_BODY_LENGTH = 20000;

    /** @var array<string, Category>|null */
    private ?array $categories = null;

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ForumSearchAudiences $audiences,
        private readonly ForumUrlsInterface $urls,
    ) {}

    /**
     * @return Builder<DiscussionModel>
     */
    public static function discussions(): Builder
    {
        return self::onlyListedDiscussions(DiscussionModel::query());
    }

    /**
     * @return Builder<PostModel>
     */
    public static function replies(): Builder
    {
        return PostModel::query()
            ->where('status', PostStatus::Visible->value)
            ->where('number', '>', 1)
            ->whereNotNull('published_at')
            ->whereHas('discussion', static fn (Builder $discussion): Builder => self::onlyListedDiscussions($discussion));
    }

    /**
     * @param  iterable<DiscussionModel>  $discussions
     * @return list<SearchDocumentDTO>
     */
    public function discussionDocuments(iterable $discussions): array
    {
        $discussions = $discussions instanceof Collection ? $discussions : new Collection(is_array($discussions) ? $discussions : iterator_to_array($discussions, false));
        $openings = $discussions->isEmpty() ? [] : PostModel::query()
            ->whereIn('discussion_id', $discussions->modelKeys())
            ->where('number', 1)
            ->where('status', PostStatus::Visible->value)
            ->get(['id', 'discussion_id', 'plain_text'])
            ->keyBy('discussion_id');

        $documents = [];
        foreach ($discussions as $discussion) {
            $opening = $openings[$discussion->id] ?? null;
            $document = $this->discussion($discussion, $opening instanceof PostModel ? $opening->plain_text : '');
            if ($document instanceof SearchDocumentDTO) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function discussion(DiscussionModel $discussion, string $openingText): ?SearchDocumentDTO
    {
        $category = $this->categories()[strtolower($discussion->category_id)] ?? null;
        $audience = $category instanceof Category ? $this->audiences->for($category, $this->categories()) : null;
        if (! $category instanceof Category || $audience === null || $discussion->number === null) {
            return null;
        }

        return new SearchDocumentDTO(
            id: $discussion->id,
            title: $discussion->title,
            body: mb_substr($openingText, 0, self::MAX_BODY_LENGTH),
            url: $this->urls->discussionFrom($discussion->number, $discussion->title),
            meta: $category->name(),
            publishedAt: $discussion->created_at->toDateTimeImmutable(),
            audience: $audience,
        );
    }

    public function reply(PostModel $post, DiscussionModel $discussion): ?SearchDocumentDTO
    {
        $category = $this->categories()[strtolower($discussion->category_id)] ?? null;
        $audience = $category instanceof Category ? $this->audiences->for($category, $this->categories()) : null;
        if (! $category instanceof Category || $audience === null || $discussion->number === null || $post->number === null) {
            return null;
        }

        return new SearchDocumentDTO(
            id: $post->id,
            title: $discussion->title,
            body: mb_substr($post->plain_text, 0, self::MAX_BODY_LENGTH),
            url: $this->urls->discussionFrom($discussion->number, $discussion->title, $post->number),
            meta: __('forum::messages.search.post_meta', ['number' => $post->number, 'category' => $category->name()]),
            publishedAt: $post->published_at?->toDateTimeImmutable(),
            audience: $audience,
        );
    }

    /**
     * @param  Builder<DiscussionModel>  $query
     * @return Builder<DiscussionModel>
     */
    private static function onlyListedDiscussions(Builder $query): Builder
    {
        return $query->whereNotNull('number')->where('is_hidden', false)->where('awaiting_approval', false);
    }

    /**
     * @return array<string, Category>
     */
    private function categories(): array
    {
        if ($this->categories === null) {
            $this->categories = [];
            // Rules that cannot be read are left out by the repository: their documents fail closed
            foreach ($this->categoryRepository->all() as $category) {
                $this->categories[$category->id()->value] = $category;
            }
        }

        return $this->categories;
    }
}
