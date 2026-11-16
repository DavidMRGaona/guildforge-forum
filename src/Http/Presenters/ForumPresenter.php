<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\DTOs\ConversationPageDTO;
use Modules\Forum\Application\DTOs\MovedPostsDTO;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Application\DTOs\PostLikesDTO;
use Modules\Forum\Application\DTOs\PostWriterDTO;
use Modules\Forum\Application\DTOs\WriterNameDTO;
use Modules\Forum\Application\Queries\CategoryStatsDTO;
use Modules\Forum\Application\Queries\CategoryStatsQueryInterface;
use Modules\Forum\Application\Queries\DiscussionListPageDTO;
use Modules\Forum\Application\Queries\DiscussionRowDTO;
use Modules\Forum\Application\Queries\MovedPostsQueryInterface;
use Modules\Forum\Application\Services\CharacterWriterNames;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\PostReferenceLabels;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\MemberTitleRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;

/**
 * The forum's page props: plain arrays with display times (in the display zone) and the
 * addresses each item links to. Names are looked up once per page.
 */
final readonly class ForumPresenter
{
    /** The query that asks a discussion's page by its place, for a page whose numbers all moved away */
    public const string PAGE_QUERY = 'pagina';

    /** What a page asked for by its place looks like: a whole number from 1, nothing around it */
    private const string PAGE_PATTERN = '/\A[1-9][0-9]{0,5}\z/';

    /** The query that shows one person's messages of a discussion */
    public const string AUTHOR_QUERY = 'autor';

    public function __construct(
        private ForumUrlsInterface $urls,
        private AccountDirectoryInterface $accounts,
        private TimezoneServiceInterface $timezone,
        private CategoryStatsQueryInterface $stats,
        private CharacterPresenter $characters,
        private MovedPostsQueryInterface $moved,
        private MemberTitleRepositoryInterface $titles,
        private PostReferenceLabels $referenceLabels,
        private CharacterWriterNames $writerNames,
        private SystemAccountInterface $systemAccount,
    ) {}

    /**
     * What the search box of a page needs: where it goes, the categories to look in and the one chosen.
     *
     * @param  list<CategoryAccessDTO>  $readable
     * @return array{url: string, category: string|null, categories: list<array{slug: string, name: string, depth: int}>}
     */
    public function searchBox(array $readable, ?Category $within = null): array
    {
        return [
            'url' => route('forum.search', [], false),
            'category' => $within?->slug()->value,
            'categories' => array_map(static fn (CategoryAccessDTO $access): array => [
                'slug' => $access->category->slug()->value,
                'name' => $access->category->name(),
                'depth' => $access->category->parentId() instanceof CategoryId ? 1 : 0,
            ], $readable),
        ];
    }

    /**
     * Top-level categories as cards, each with its readable subcategories.
     *
     * @param  list<CategoryAccessDTO>  $readable
     * @return list<array<string, mixed>>
     */
    public function categoryTree(array $readable, ?CategoryId $under = null, ?Authenticatable $reader = null): array
    {
        $stats = $this->stats->statsOf(array_map(static fn (CategoryAccessDTO $access): string => $access->category->id()->value, $readable));
        $names = $this->names(array_values(array_filter(array_map(static fn (CategoryStatsDTO $stat): ?string => $stat->lastPostAuthorId, $stats))));
        $names = [...$names, ...$this->characterWriterNames(array_map(
            static fn (CategoryStatsDTO $stat): PostWriterDTO => new PostWriterDTO($stat->lastPostAuthorId, $stat->lastPostCharacterId, $stat->lastPostCharacterRemoved),
            $this->keyed($stats, 'last'),
        ), $reader)];

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
        $readOnly = $category->isReadOnly();
        $parentId = $category->parentId();
        if ($parentId instanceof CategoryId) {
            foreach ($readable as $candidate) {
                if ($candidate->category->id()->equals($parentId)) {
                    $parent = ['name' => $candidate->category->name(), 'url' => $this->urls->category($candidate->category)];
                    $readOnly = $readOnly || $candidate->category->isReadOnly();
                }
            }
        }

        return [
            ...$this->categoryBasics($category),
            'parent' => $parent,
            'canModerate' => $access->canModerate,
            // The header tells that the forum's moderators may enter a private space, and when it takes no posts
            'isPublicationSpace' => $category->isPublicationSpace(),
            'readOnly' => $readOnly,
            // Only the forum's moderators reach one that is not: the header tells them nobody else sees it
            'unavailable' => ! $access->available,
        ];
    }

    /**
     * @param  list<CategoryAccessDTO>  $readable  to name each row's category
     * @return array{pinned: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function discussionList(DiscussionListPageDTO $page, array $readable, ?Authenticatable $reader = null): array
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
        $writers = [];
        foreach ($rows as $row) {
            $writers['starter:'.$row->id] = new PostWriterDTO($row->authorId, $row->starterCharacterId, $row->starterCharacterRemoved);
            $writers['last:'.$row->id] = new PostWriterDTO($row->lastPostAuthorId, $row->lastPostCharacterId, $row->lastPostCharacterRemoved);
        }
        $names = [...$names, ...$this->characterWriterNames($writers, $reader)];

        // Where a row resumes when the first unread number moved away: the first post after it
        $resumeFrom = [];
        foreach ($rows as $row) {
            if ($row->lastReadNumber !== null && $row->lastReadNumber > 0 && $row->lastPostNumber !== null && $row->lastReadNumber < $row->lastPostNumber) {
                $resumeFrom[$row->id] = $row->lastReadNumber + 1;
            }
        }
        $resume = $this->moved->resumeAfterMoves($resumeFrom);

        // Numbers that left a row after what was read are not unread posts: counted apart, and
        // only for the rows with something unread
        $unreadSpans = [];
        foreach ($rows as $row) {
            if ($row->lastReadNumber !== null && $row->lastPostNumber !== null && $row->lastReadNumber < $row->lastPostNumber) {
                $unreadSpans[$row->id] = ['after' => $row->lastReadNumber, 'through' => $row->lastPostNumber];
            }
        }
        $moved = $this->moved->countInRanges($unreadSpans);

        $present = fn (DiscussionRowDTO $row): array => $this->discussionRow($row, $categories[$row->categoryId] ?? null, $names, $resume[$row->id] ?? null, $moved[$row->id] ?? 0);

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
        ['posts' => $posts, 'mentionNames' => $mentionNames] = $this->posts($discussion, $page->posts, $page->contents, $page->canModerate);

        $categoryAccess = null;
        foreach ($readable as $access) {
            if ($access->category->id()->equals($page->category->id())) {
                $categoryAccess = $access;
            }
        }

        $pageUrl = fn (int $start): string => $this->pageUrl($page, $start);
        $firstUrl = $page->filtered ? $pageUrl(1) : $this->urls->discussion($discussion);
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
                'isAwaitingApproval' => $discussion->isAwaitingApproval(),
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
                'firstUrl' => $page->hasPrevious() ? $firstUrl : null,
                'previousUrl' => $page->hasPrevious() ? $pageUrl(max(1, $page->pageStart - $page->perPage)) : null,
                'nextUrl' => $page->hasNext() ? $pageUrl($page->pageStart + $page->perPage) : null,
                'lastUrl' => $page->hasNext() ? $pageUrl($lastStart) : null,
            ],
            // Where the page's posts that moderation moved away went, in their place
            'moved' => array_map(fn (MovedPostsDTO $moved): array => [
                'fromNumber' => $moved->fromNumber,
                'throughNumber' => $moved->throughNumber,
                'count' => $moved->count,
                'title' => $moved->target?->title()->value,
                'url' => $moved->target instanceof Discussion ? $this->urls->discussion($moved->target, $moved->targetNumber) : null,
            ], $page->moved),
            // One person's messages only (?autor=): who, how many and the way back to the whole discussion
            'filter' => $page->filtered ? [
                'authorName' => $this->filteredAuthorName($page, $posts),
                'count' => $page->highestNumber,
                // Posts awaiting approval on the page (only their author and moderation get them): not in the count
                'pending' => count(array_filter($posts, static fn (array $post): bool => $post['status'] === PostStatus::Pending->value)),
                'clearUrl' => $this->urls->discussion($discussion),
            ] : null,
            'focusNumber' => $page->focusNumber,
            'lastReadNumber' => $page->lastReadNumber,
            'mentionNames' => $mentionNames,
        ];
    }

    /**
     * Who the filtered messages are by, as the page shows them: only when it shows any (every
     * one of them already names its writer), so nothing is said about a person without messages.
     *
     * @param  list<array<string, mixed>>  $posts  as `posts()` built them
     */
    private function filteredAuthorName(ConversationPageDTO $page, array $posts): ?string
    {
        foreach ($posts as $post) {
            if ($post['authorId'] === $page->filterAuthorId && is_string($post['authorName'])) {
                return $post['authorName'];
            }
        }

        return null;
    }

    /**
     * The page a request asks for by its place (?pagina=), or null when it asks for none or for
     * something that is not a page number.
     */
    public static function askedPage(Request $request): ?int
    {
        $page = $request->query(self::PAGE_QUERY);

        return is_string($page) && preg_match(self::PAGE_PATTERN, $page) === 1 ? (int) $page : null;
    }

    /**
     * The address of the discussion's page starting at `$start`: by its first number, by its
     * first post's when that number moved away (its address leads where it went), or by its
     * place (?pagina=) when all its numbers did.
     */
    public function pageUrl(ConversationPageDTO $page, int $start, bool $absolute = false): string
    {
        // One person's messages: the page is its place among them, never a post's number
        if ($page->filtered) {
            $query = [self::AUTHOR_QUERY => $page->filterAuthorId, ...($start > 1 ? [self::PAGE_QUERY => intdiv($start - 1, $page->perPage) + 1] : [])];

            return $this->urls->discussion($page->discussion, absolute: $absolute).'?'.http_build_query($query);
        }

        $anchor = $page->anchorOf($start);
        if ($anchor !== null) {
            return $this->urls->discussion($page->discussion, $anchor, $absolute);
        }

        return $this->urls->discussion($page->discussion, absolute: $absolute).'?'.http_build_query([self::PAGE_QUERY => intdiv($start - 1, $page->perPage) + 1]);
    }

    /**
     * A post's likes as the page shows them: the count, whether the reader gave one and the
     * names of the latest ones, with how many more there are.
     *
     * @param  array<string, string>  $names  by account id
     * @return array{count: int, liked: bool, names: list<string>, others: int}
     */
    public static function likes(PostLikesDTO $likes, array $names): array
    {
        $shown = array_values(array_filter(array_map(static fn (string $id): ?string => $names[$id] ?? null, $likes->latestAccountIds), is_string(...)));

        return [
            'count' => $likes->count,
            'liked' => $likes->likedByViewer,
            'names' => $shown,
            'others' => max(0, $likes->count - count($shown)),
        ];
    }

    /**
     * The posts as the conversation draws them, and the names of whoever the editable ones
     * mention (their editor starts from the stored document).
     *
     * @param  list<Post>  $posts
     * @param  list<PostContentDTO>  $contents  what each post shows to the reader, in the same order
     * @return array{posts: list<array<string, mixed>>, mentionNames: array<string, string>}
     */
    public function posts(Discussion $discussion, array $posts, array $contents, bool $moderates = false): array
    {
        // The editor of a post its author may change starts from its stored document
        $editable = [];
        foreach ($posts as $index => $post) {
            if ($contents[$index]->canEdit) {
                $editable[$post->id()->value] = $post->body()->document;
            }
        }
        // Its references show the number each post has now
        $editable = $this->referenceLabels->fillMany($editable);
        $mentioned = [];
        foreach ($editable as $document) {
            self::collectMentions($document, $mentioned);
        }

        // A post that speaks as a shared character shows no account to whoever may not know it,
        // nor one as a personal character (living or deleted) of someone without a public name
        // (forum#139): not even its id, which their posts out of character carry with their name
        $shown = [];
        foreach ($posts as $index => $post) {
            $shown[$index] = $post->authorId() !== null && ! $contents[$index]->authorHidden && ! $contents[$index]->unnamedWriter instanceof WriterNameDTO
                ? $post->authorId()
                : null;
        }

        $names = $this->names(array_values(array_unique([
            ...array_filter($shown),
            ...array_keys($mentioned),
        ])));
        // Read from the account each time (never copied), only for authors the reader may know
        $avatars = $this->accounts->avatarsOf(array_values(array_unique(array_filter($shown))));
        // Titles of the authors the reader may know, read the same way and in one query
        $titles = $this->titles->titlesOf(array_values(array_unique(array_filter($shown))));

        // The forum's own account has no card. Asked once per author shown, never per post: there
        // is no plain read of its id (`id()` creates the account when it is missing)
        $forumAccount = array_flip(array_filter(
            array_values(array_unique(array_filter($shown))),
            $this->systemAccount->isSystemAccount(...),
        ));

        $items = [];
        foreach ($posts as $index => $post) {
            $content = $contents[$index];
            $number = $post->number()?->value;
            $published = $post->publishedAt() ?? $post->createdAt();

            $items[] = [
                'id' => $post->id()->value,
                'number' => $number,
                'authorId' => $shown[$index],
                'authorName' => $this->authorName($content, $shown[$index], $names),
                // The account exists but is not shown (no public name): never «Cuenta eliminada»
                'writerUnnamed' => $content->unnamedWriter instanceof WriterNameDTO,
                // A post in character shows the character's portrait, never the account's avatar
                'authorAvatarPublicId' => $shown[$index] === null || $content->character instanceof Character ? null : ($avatars[$shown[$index]] ?? null),
                // In character the fiction rules: neither the account's avatar nor its title
                'authorTitle' => $shown[$index] === null || $content->character instanceof Character ? null : ($titles[$shown[$index]] ?? null),
                // The card the writer's name opens (forum#134): only where the post names a person
                'authorCardUrl' => $shown[$index] === null || isset($forumAccount[$shown[$index]])
                    ? null
                    : route('forum.posts.author', ['post' => $post->id()->value]),
                'isStarter' => $shown[$index] !== null && $shown[$index] === $discussion->authorId(),
                'status' => $post->status()->value,
                'html' => $content->html,
                'placeholder' => $content->placeholder,
                // What a quotation of the whole post is checked against: its rendered text also
                // holds the labels of rolls, asides and embeds. Only for a post shown as it is
                'quoteText' => $post->status() === PostStatus::Visible && $content->html !== null ? $post->body()->plainText : null,
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
                'hiddenReason' => $content->hiddenReason,
                // Who it speaks as, when the reader may see that character; its editor starts from it
                'character' => $content->character instanceof Character ? $this->characters->card($content->character, $content->moderatesCharacter) : null,
                'characterId' => $content->canEdit ? $post->characterId()?->value : null,
                // Its moderation menu, for whoever moderates the discussion
                'moderationUrl' => $moderates ? route('forum.moderation.posts', ['post' => $post->id()->value]) : null,
                // The visible posts that reply to it (by «#N» or quoting it) the reader may read
                'replyCount' => $content->replyCount,
                'repliesUrl' => $content->replyCount > 0 ? route('forum.posts.replies', ['post' => $post->id()->value]) : null,
            ];
        }

        return ['posts' => $items, 'mentionNames' => array_intersect_key($names, $mentioned)];
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
                'authorName' => $stat->lastPostSharedName ?? $names['last:'.$category->id()->value] ?? ($stat->lastPostAuthorId === null ? null : ($names[$stat->lastPostAuthorId] ?? null)),
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
    private function discussionRow(DiscussionRowDTO $row, ?Category $category, array $names, ?int $resumeAt = null, int $movedAway = 0): array
    {
        $unreadCount = $row->lastReadNumber === null || $row->lastPostNumber === null
            ? null
            : max(0, $row->lastPostNumber - $row->lastReadNumber - $movedAway);

        return [
            'id' => $row->id,
            'number' => $row->number,
            'title' => $row->title,
            // The list listens to the real-time channel of each category it shows
            'categoryId' => $row->categoryId,
            'url' => $this->urls->discussionFrom($row->number, $row->title),
            // Where reading should resume: the first unread post, or the last one once all is read
            'resumeUrl' => $row->lastReadNumber === null || $row->lastPostNumber === null || $row->lastReadNumber === 0
                ? null
                : $this->urls->discussionFrom($row->number, $row->title, min($resumeAt ?? $row->lastReadNumber + 1, $row->lastPostNumber)),
            'category' => $category instanceof Category
                ? ['name' => $category->name(), 'url' => $this->urls->category($category), 'color' => $category->appearance()->color]
                : null,
            'authorName' => $names['starter:'.$row->id] ?? ($row->authorId === null ? null : ($names[$row->authorId] ?? null)),
            'topics' => $this->topics($row->topics),
            'replyCount' => max(0, $row->postCount - 1),
            'lastPost' => [
                'authorName' => $row->lastPostSharedName ?? $names['last:'.$row->id] ?? ($row->lastPostAuthorId === null ? null : ($names[$row->lastPostAuthorId] ?? null)),
                'at' => $this->timezone->iso($row->lastActivityAt),
                'atLabel' => $this->timezone->format($row->lastActivityAt),
                'url' => $row->lastPostNumber === null ? null : $this->urls->discussionFrom($row->number, $row->title, $row->lastPostNumber),
            ],
            // What the row showed when the page drew it: the list compares it with the forum's later answers
            'lastPostNumber' => $row->lastPostNumber,
            'isPinned' => $row->pinned,
            'isClosed' => $row->status === DiscussionStatus::Closed,
            'isHidden' => $row->hidden,
            'isUnread' => $row->lastReadNumber === null ? null : $row->isUnread(),
            'unreadCount' => $unreadCount,
        ];
    }

    /**
     * How the writers of these posts are named when they speak, or spoke, as a personal
     * character (CharacterWriterNames), keyed like `$writers`; their own posts name the reader's
     * account as always.
     *
     * @param  array<string, PostWriterDTO>  $writers
     * @return array<string, string>
     */
    private function characterWriterNames(array $writers, ?Authenticatable $reader): array
    {
        $readerId = ForumBrowser::accountId($reader);
        $others = array_filter($writers, static fn (PostWriterDTO $writer): bool => $readerId === null || $writer->authorId === null || strtolower($writer->authorId) !== $readerId);

        return array_map(static fn (WriterNameDTO $name): string => $name->name, $this->writerNames->namesFor($others, $reader));
    }

    /**
     * The items with each key prefixed, so names by post never meet names by account id.
     *
     * @template T
     *
     * @param  array<string, T>  $items
     * @return array<string, T>
     */
    private function keyed(array $items, string $prefix): array
    {
        return array_combine(array_map(static fn (string $key): string => $prefix.':'.$key, array_keys($items)), array_values($items));
    }

    /**
     * The name a post shows for its writer. In character, without a public name, the character
     * speaks alone (no «por …» line); once that character is deleted, a neutral label.
     *
     * @param  array<string, string>  $names  by account id
     */
    private function authorName(PostContentDTO $content, ?string $shownId, array $names): ?string
    {
        $unnamed = $content->unnamedWriter;
        if ($unnamed instanceof WriterNameDTO) {
            return $unnamed->seenCharacter instanceof Character ? null : $unnamed->name;
        }

        return $shownId === null ? null : ($names[$shownId] ?? null);
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
