<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\DTOs\ConversationPageDTO;
use Modules\Forum\Application\Queries\CategoryStatsDTO;
use Modules\Forum\Application\Queries\CategoryStatsQueryInterface;
use Modules\Forum\Application\Queries\DiscussionListPageDTO;
use Modules\Forum\Application\Queries\DiscussionRowDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;

/**
 * The forum's page props: plain arrays with display times (in the display zone) and the
 * addresses each item links to. Names are looked up once per page.
 */
final readonly class ForumPresenter
{
    public function __construct(
        private ForumUrlsInterface $urls,
        private AccountDirectoryInterface $accounts,
        private TimezoneServiceInterface $timezone,
        private CategoryStatsQueryInterface $stats,
    ) {}

    /**
     * Top-level categories as cards, each with its readable subcategories.
     *
     * @param  list<CategoryAccessDTO>  $readable
     * @return list<array<string, mixed>>
     */
    public function categoryTree(array $readable, ?CategoryId $under = null): array
    {
        $stats = $this->stats->statsOf(array_map(static fn (CategoryAccessDTO $access): string => $access->category->id()->value, $readable));
        $names = $this->names(array_values(array_filter(array_map(static fn (CategoryStatsDTO $stat): ?string => $stat->lastPostAuthorId, $stats))));

        $cards = [];
        foreach ($readable as $access) {
            $parentId = $access->category->parentId();
            $belongs = $under instanceof CategoryId
                ? $parentId instanceof CategoryId && $parentId->equals($under)
                : ! $parentId instanceof CategoryId;

            if (! $belongs) {
                continue;
            }

            $card = $this->categoryCard($access->category, $stats, $names);
            $card['children'] = [];

            if (! $under instanceof CategoryId) {
                foreach ($readable as $child) {
                    $childParent = $child->category->parentId();
                    if ($childParent instanceof CategoryId && $childParent->equals($access->category->id())) {
                        $card['children'][] = $this->categoryCard($child->category, $stats, $names);
                    }
                }
            }

            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * @param  list<CategoryAccessDTO>  $readable
     * @return array<string, mixed>
     */
    public function categoryHeader(CategoryAccessDTO $access, array $readable): array
    {
        $category = $access->category;
        $parent = null;
        $parentId = $category->parentId();
        if ($parentId instanceof CategoryId) {
            foreach ($readable as $candidate) {
                if ($candidate->category->id()->equals($parentId)) {
                    $parent = ['name' => $candidate->category->name(), 'url' => $this->urls->category($candidate->category)];
                }
            }
        }

        return [
            ...$this->categoryBasics($category),
            'parent' => $parent,
            'canModerate' => $access->canModerate,
        ];
    }

    /**
     * @param  list<CategoryAccessDTO>  $readable  to name each row's category
     * @return array{pinned: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function discussionList(DiscussionListPageDTO $page, array $readable): array
    {
        $categories = [];
        foreach ($readable as $access) {
            $categories[$access->category->id()->value] = $access->category;
        }

        $rows = [...$page->pinned, ...$page->items];
        $names = $this->names(array_values(array_filter([
            ...array_map(static fn (DiscussionRowDTO $row): ?string => $row->authorId, $rows),
            ...array_map(static fn (DiscussionRowDTO $row): ?string => $row->lastPostAuthorId, $rows),
        ])));

        $present = fn (DiscussionRowDTO $row): array => $this->discussionRow($row, $categories[$row->categoryId] ?? null, $names);

        return [
            'pinned' => array_map($present, $page->pinned),
            'items' => array_map($present, $page->items),
        ];
    }

    /**
     * @param  list<DiscussionTopic>  $topics
     * @return list<array{key: string, label: string}>
     */
    public function topics(array $topics): array
    {
        return array_map(static fn (DiscussionTopic $topic): array => ['key' => $topic->key, 'label' => $topic->label], $topics);
    }

    /**
     * @param  list<CategoryAccessDTO>  $readable
     * @return array<string, mixed>
     */
    public function conversation(ConversationPageDTO $page, array $readable): array
    {
        $discussion = $page->discussion;
        // The editor of a post its author may change starts from its stored document
        $editable = [];
        foreach ($page->posts as $index => $post) {
            if ($page->contents[$index]->canEdit) {
                $editable[$post->id()->value] = $post->body()->document;
            }
        }
        $mentioned = [];
        foreach ($editable as $document) {
            self::collectMentions($document, $mentioned);
        }

        $names = $this->names(array_values(array_unique([
            ...array_filter(array_map(static fn ($post): ?string => $post->authorId(), $page->posts)),
            ...array_keys($mentioned),
        ])));

        $posts = [];
        foreach ($page->posts as $index => $post) {
            $content = $page->contents[$index];
            $number = $post->number()?->value;
            $published = $post->publishedAt() ?? $post->createdAt();

            $posts[] = [
                'id' => $post->id()->value,
                'number' => $number,
                'authorId' => $post->authorId(),
                'authorName' => $post->authorId() === null ? null : ($names[$post->authorId()] ?? null),
                'isStarter' => $post->authorId() !== null && $post->authorId() === $discussion->authorId(),
                'status' => $post->status()->value,
                'html' => $content->html,
                'placeholder' => $content->placeholder,
                'publishedAt' => $this->timezone->iso($published),
                'publishedLabel' => $this->timezone->format($published),
                'editedAt' => $this->timezone->iso($post->editedAt()),
                'editedLabel' => $post->editedAt() instanceof DateTimeImmutable && $post->status() !== PostStatus::Deleted
                    ? $this->timezone->format($post->editedAt())
                    : null,
                'url' => $number === null ? null : $this->urls->discussion($discussion, $number),
                'canEdit' => $content->canEdit,
                'canDelete' => $content->canDelete,
                'document' => $editable[$post->id()->value] ?? null,
                'updateUrl' => $content->canEdit ? route('forum.posts.update', ['post' => $post->id()->value]) : null,
                'deleteUrl' => $content->canDelete ? route('forum.posts.destroy', ['post' => $post->id()->value]) : null,
            ];
        }

        $categoryAccess = null;
        foreach ($readable as $access) {
            if ($access->category->id()->equals($page->category->id())) {
                $categoryAccess = $access;
            }
        }

        $pageUrl = fn (int $start): string => $this->urls->discussion($discussion, $start);
        $lastStart = ($page->pageCount() - 1) * $page->perPage + 1;

        return [
            'discussion' => [
                'id' => $discussion->id()->value,
                'number' => $discussion->number(),
                'title' => $discussion->title()->value,
                'url' => $this->urls->discussion($discussion),
                'isClosed' => $discussion->status() === DiscussionStatus::Closed,
                'isPinned' => $discussion->isPinned(),
                'isHidden' => $discussion->isHidden(),
                'topics' => $this->topics($page->topics),
                'category' => $categoryAccess instanceof CategoryAccessDTO
                    ? $this->categoryHeader($categoryAccess, $readable)
                    : [...$this->categoryBasics($page->category), 'parent' => null, 'canModerate' => false],
            ],
            'posts' => $posts,
            'pagination' => [
                'page' => $page->page(),
                'pageCount' => $page->pageCount(),
                'perPage' => $page->perPage,
                'highestNumber' => $page->highestNumber,
                'firstUrl' => $page->hasPrevious() ? $this->urls->discussion($discussion) : null,
                'previousUrl' => $page->hasPrevious() ? $pageUrl(max(1, $page->pageStart - $page->perPage)) : null,
                'nextUrl' => $page->hasNext() ? $pageUrl($page->pageStart + $page->perPage) : null,
                'lastUrl' => $page->hasNext() ? $pageUrl($lastStart) : null,
            ],
            'focusNumber' => $page->focusNumber,
            'lastReadNumber' => $page->lastReadNumber,
            'mentionNames' => array_intersect_key($names, $mentioned),
        ];
    }

    /**
     * The account ids a document mentions.
     *
     * @param  array<mixed>  $node
     * @param  array<string, true>  $ids
     */
    private static function collectMentions(array $node, array &$ids): void
    {
        if (($node['type'] ?? null) === 'mention' && is_array($node['attrs'] ?? null) && is_string($node['attrs']['id'] ?? null)) {
            $ids[strtolower($node['attrs']['id'])] = true;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::collectMentions($child, $ids);
            }
        }
    }

    /**
     * @param  array<string, CategoryStatsDTO>  $stats
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function categoryCard(Category $category, array $stats, array $names): array
    {
        $stat = $stats[$category->id()->value] ?? new CategoryStatsDTO(0, 0);

        $lastPost = null;
        if ($stat->lastActivityAt instanceof DateTimeImmutable && $stat->lastDiscussionTitle !== null && $stat->lastDiscussionNumber !== null) {
            $lastPost = [
                'discussionTitle' => $stat->lastDiscussionTitle,
                'url' => $this->urls->discussionFrom($stat->lastDiscussionNumber, $stat->lastDiscussionTitle, $stat->lastPostNumber),
                'authorName' => $stat->lastPostAuthorId === null ? null : ($names[$stat->lastPostAuthorId] ?? null),
                'at' => $this->timezone->iso($stat->lastActivityAt),
                'atLabel' => $this->timezone->format($stat->lastActivityAt),
            ];
        }

        return [
            ...$this->categoryBasics($category),
            'discussionCount' => $stat->discussionCount,
            'postCount' => $stat->postCount,
            'lastPost' => $lastPost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryBasics(Category $category): array
    {
        return [
            'id' => $category->id()->value,
            'name' => $category->name(),
            'slug' => $category->slug()->value,
            'description' => $category->description(),
            'url' => $this->urls->category($category),
            'color' => $category->appearance()->color,
            'icon' => $category->appearance()->icon?->value,
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function discussionRow(DiscussionRowDTO $row, ?Category $category, array $names): array
    {
        $unreadCount = $row->lastReadNumber === null || $row->lastPostNumber === null
            ? null
            : max(0, $row->lastPostNumber - $row->lastReadNumber);

        return [
            'id' => $row->id,
            'number' => $row->number,
            'title' => $row->title,
            'url' => $this->urls->discussionFrom($row->number, $row->title),
            // Where reading should resume: the first unread post, or the last one once all is read
            'resumeUrl' => $row->lastReadNumber === null || $row->lastPostNumber === null || $row->lastReadNumber === 0
                ? null
                : $this->urls->discussionFrom($row->number, $row->title, min($row->lastReadNumber + 1, $row->lastPostNumber)),
            'category' => $category instanceof Category
                ? ['name' => $category->name(), 'url' => $this->urls->category($category), 'color' => $category->appearance()->color]
                : null,
            'authorName' => $row->authorId === null ? null : ($names[$row->authorId] ?? null),
            'topics' => $this->topics($row->topics),
            'replyCount' => max(0, $row->postCount - 1),
            'lastPost' => [
                'authorName' => $row->lastPostAuthorId === null ? null : ($names[$row->lastPostAuthorId] ?? null),
                'at' => $this->timezone->iso($row->lastActivityAt),
                'atLabel' => $this->timezone->format($row->lastActivityAt),
                'url' => $row->lastPostNumber === null ? null : $this->urls->discussionFrom($row->number, $row->title, $row->lastPostNumber),
            ],
            'isPinned' => $row->pinned,
            'isClosed' => $row->status === DiscussionStatus::Closed,
            'isHidden' => $row->hidden,
            'isUnread' => $row->lastReadNumber === null ? null : $row->isUnread(),
            'unreadCount' => $unreadCount,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function names(array $ids): array
    {
        return $ids === [] ? [] : $this->accounts->namesOf(array_values(array_unique($ids)));
    }
}
