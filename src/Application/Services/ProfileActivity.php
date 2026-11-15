<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\Forum\Application\Content\Nodes\MentionNode;
use Modules\Forum\Application\Content\Nodes\PostQuoteNode;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\DTOs\ProfilePostDTO;
use Modules\Forum\Application\Queries\ProfileActivityCriteria;
use Modules\Forum\Application\Queries\ProfileActivityQueryInterface;
use Modules\Forum\Application\Queries\ProfilePostRowDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\ValueObjects\AccountId;

/**
 * What a person did in the forum, as their public profile shows it to whoever looks (forum#135).
 * Everything starts from the forum's own rules for that reader: nothing in a forum in preview they
 * may not see, only the categories they may read, and only visible messages of visible topics
 * (never hidden, deleted or pending ones, not even for moderation: a profile is not the place to
 * moderate). A message that speaks as a shared character counts only where the reader may know
 * its writer, and one as a personal character only where the forum's filter by author would list
 * it, both by the rules `PostContentPresenter` keeps for the discussion pages.
 *
 * Bound per request (or queue job), like the access rules it reads: the scope of each owner and
 * reader is worked out once and shared by the section and the counters.
 */
final class ProfileActivity
{
    public const int EXCERPT_LENGTH = 200;

    /** @var array<string, array{ProfileActivityCriteria, array<string, Category>}|null> */
    private array $scopes = [];

    public function __construct(
        private readonly ForumVisibility $visibility,
        private readonly ForumBrowser $browser,
        private readonly PostContentPresenter $presenter,
        private readonly ProfileActivityQueryInterface $query,
        private readonly DocumentRenderer $renderer,
        private readonly AccountDirectoryInterface $accounts,
    ) {}

    /**
     * The person's latest messages the reader may read, newest first.
     *
     * @return list<ProfilePostDTO>
     */
    public function latestPosts(string $ownerId, ?Authenticatable $reader, int $limit): array
    {
        $scope = $this->scope($ownerId, $reader);
        if ($scope === null) {
            return [];
        }

        [$criteria, $categories] = $scope;
        $rows = $this->query->latestPosts($criteria, $limit);
        $documents = array_map(static fn (ProfilePostRowDTO $row): array => self::withoutQuotes($row->document), $rows);
        $context = new RenderContext(accountNames: $this->mentionNames($documents));

        $posts = [];
        foreach ($rows as $index => $row) {
            $category = $categories[$row->categoryId] ?? null;
            if ($category instanceof Category) {
                $posts[] = new ProfilePostDTO($row, $category, $this->excerpt($documents[$index], $context));
            }
        }

        return $posts;
    }

    /**
     * How many messages the reader may read; null when there are none, for a 0 would say the
     * person wrote nothing where they may have written in a category the reader cannot read.
     */
    public function postCount(string $ownerId, ?Authenticatable $reader): ?int
    {
        $scope = $this->scope($ownerId, $reader);
        $count = $scope === null ? 0 : $this->query->countPosts($scope[0]);

        return $count > 0 ? $count : null;
    }

    /**
     * How many of the topics the person opened the reader may read; null when none, as above.
     */
    public function topicCount(string $ownerId, ?Authenticatable $reader): ?int
    {
        $scope = $this->scope($ownerId, $reader);
        $count = $scope === null ? 0 : $this->query->countTopics($scope[0]);

        return $count > 0 ? $count : null;
    }

    /**
     * What the reader may see of the person's activity, or null when they see no forum at all.
     *
     * @return array{ProfileActivityCriteria, array<string, Category>}|null
     */
    private function scope(string $ownerId, ?Authenticatable $reader): ?array
    {
        $ownerId = AccountId::normalize($ownerId);
        if ($ownerId === null) {
            return null;
        }

        $readerId = ForumBrowser::accountId($reader);
        $key = $ownerId.'|'.($readerId ?? '');
        if (! array_key_exists($key, $this->scopes)) {
            $this->scopes[$key] = $this->visibility->isVisibleTo($reader) ? $this->scopeFor($ownerId, $reader, $readerId === $ownerId) : null;
        }

        return $this->scopes[$key];
    }

    /**
     * @return array{ProfileActivityCriteria, array<string, Category>}|null
     */
    private function scopeFor(string $ownerId, ?Authenticatable $reader, bool $own): ?array
    {
        $readable = $this->browser->readableCategories($reader);
        if ($readable === []) {
            return null;
        }

        $categories = [];
        $moderated = [];
        $unmoderated = [];
        foreach ($readable as $access) {
            $id = $access->category->id()->value;
            $categories[$id] = $access->category;
            if ($access->canModerate) {
                $moderated[] = $id;
            } else {
                $unmoderated[] = $id;
            }
        }

        // Whoever moderates a category reads every writer in it; elsewhere the writer rules decide
        $hidden = [];
        $hidesCharacterPosts = false;
        if ($unmoderated !== []) {
            $use = $this->query->charactersUsedBy($ownerId, $unmoderated);
            if ($use->characterIds !== []) {
                $hidden = $this->presenter->charactersHidingTheirWriter($reader, $own, false, $use->characterIds);
            }
            if ($use->spokeAsCharacter) {
                $hidesCharacterPosts = $this->presenter->hidesCharacterPostsOf($ownerId, $own, false);
            }
        }

        return [
            new ProfileActivityCriteria(
                authorId: $ownerId,
                categoryIds: array_keys($categories),
                moderatedCategoryIds: $moderated,
                hiddenCharacterIds: $hidden,
                hidesCharacterPosts: $hidesCharacterPosts,
            ),
            $categories,
        ];
    }

    /**
     * The names the mentions of the documents show: public names only, and a neutral word for an
     * account without one, so an excerpt never names an account by its login. An account that no
     * longer exists is left out, and the mention says so as in the forum («@cuenta eliminada»).
     *
     * @param  list<array<array-key, mixed>>  $documents
     * @return array<string, string>
     */
    private function mentionNames(array $documents): array
    {
        $mention = (new MentionNode)->name();
        $ids = [];
        foreach ($documents as $document) {
            foreach ($this->renderer->attributesOf($document, [$mention])[$mention] ?? [] as $attributes) {
                $id = AccountId::normalize(is_string($attributes['id'] ?? null) ? $attributes['id'] : '');
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        $existing = $ids === [] ? [] : array_map(strval(...), array_keys($this->accounts->namesOf(array_values($ids))));
        if ($existing === []) {
            return [];
        }

        $public = $this->accounts->publicNamesOf($existing);
        $someone = __('forum::messages.profile.someone');
        $names = [];
        foreach ($existing as $id) {
            $names[$id] = $public[$id] ?? (is_string($someone) ? $someone : '');
        }

        return $names;
    }

    /**
     * @param  array<array-key, mixed>  $document
     */
    private function excerpt(array $document, RenderContext $context): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->renderer->toText($document, $context)));

        return Str::limit($text, self::EXCERPT_LENGTH, '…');
    }

    /**
     * The document without the messages it quotes: the excerpt is what the person wrote, never
     * someone else's words.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function withoutQuotes(array $node): array
    {
        if (! is_array($node['content'] ?? null)) {
            return $node;
        }

        $quote = (new PostQuoteNode)->name();
        $node['content'] = array_values(array_map(
            self::withoutQuotes(...),
            array_filter($node['content'], static fn (mixed $child): bool => is_array($child) && ($child['type'] ?? null) !== $quote),
        ));

        return $node;
    }
}
