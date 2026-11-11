<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Content\Nodes\MentionNode;
use Modules\Forum\Application\Content\Nodes\PostQuoteNode;
use Modules\Forum\Application\Content\PostDocument;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\QuoteSource;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Application\Contracts\PostLinksInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Renders post bodies for a reader. Everything a page of posts needs from outside the
 * documents (mentioned names, quoted posts, image addresses) is looked up once for all of
 * them. A quote names its author and links to the original only when the reader may read that
 * post; otherwise it shows as a plain quotation.
 */
final readonly class PostRenderer
{
    public function __construct(
        private DocumentRenderer $renderer,
        private AccountDirectoryInterface $accounts,
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
        private PostLinksInterface $links,
        private PostImageStorageInterface $images,
    ) {}

    /**
     * @param  list<PostBody>  $bodies
     * @return list<string> the HTML of each, in the same order
     */
    public function toHtml(array $bodies, ?Authenticatable $reader): array
    {
        $context = $this->contextFor($bodies, $reader);

        return array_map(fn (PostBody $body): string => $this->renderer->toHtml($body->document, $context), $bodies);
    }

    /**
     * The plain text of a document about to be stored, with the names mentioned accounts have
     * now.
     */
    public function toText(PostDocument $document): string
    {
        return $this->renderer->toText(
            $document->toArray(),
            new RenderContext(accountNames: $this->accounts->namesOf($document->mentionedAccountIds())),
        );
    }

    /**
     * The quotations of a document about to be stored, each with the post it names and its
     * text as plain text.
     *
     * @return list<array{postId: string, text: string}>
     */
    public function quotations(PostDocument $document): array
    {
        $context = new RenderContext(accountNames: $this->accounts->namesOf($document->mentionedAccountIds()));
        $quotations = [];

        foreach ($this->renderer->nodesOf($document->toArray(), (new PostQuoteNode)->name()) as $node) {
            $postId = is_array($node['attrs'] ?? null) ? ($node['attrs']['postId'] ?? null) : null;
            if (is_string($postId)) {
                $quotations[] = [
                    'postId' => $postId,
                    'text' => $this->renderer->toText(['type' => 'doc', 'content' => $node['content'] ?? []], $context),
                ];
            }
        }

        return $quotations;
    }

    /**
     * @param  list<PostBody>  $bodies
     */
    private function contextFor(array $bodies, ?Authenticatable $reader): RenderContext
    {
        $mention = (new MentionNode)->name();
        $quote = (new PostQuoteNode)->name();
        $accountIds = [];
        $postIds = [];

        foreach ($bodies as $body) {
            $found = $this->renderer->attributesOf($body->document, [$mention, $quote]);
            foreach ($found[$mention] ?? [] as $attributes) {
                $accountIds[] = AccountId::normalize(is_string($attributes['id'] ?? null) ? $attributes['id'] : '');
            }
            foreach ($found[$quote] ?? [] as $attributes) {
                $postIds[] = AccountId::normalize(is_string($attributes['postId'] ?? null) ? $attributes['postId'] : '');
            }
        }

        return new RenderContext(
            accountNames: $this->accounts->namesOf($this->distinct($accountIds)),
            quotes: $this->quoteSources($this->distinct($postIds), $reader),
            imageUrl: fn (string $path): ?string => $this->images->url($path),
        );
    }

    /**
     * @param  list<string>  $postIds
     * @return array<string, QuoteSource>
     */
    private function quoteSources(array $postIds, ?Authenticatable $reader): array
    {
        if ($postIds === []) {
            return [];
        }

        /** @var array<string, Discussion|null> $discussions */
        $discussions = [];
        $readable = [];
        foreach ($this->posts->findMany(array_map(PostId::fromString(...), $postIds)) as $post) {
            $discussionId = $post->discussionId()->value;
            if (! array_key_exists($discussionId, $discussions)) {
                $discussion = $this->discussions->find(DiscussionId::fromString($discussionId));
                $discussions[$discussionId] = $discussion instanceof Discussion && $this->access->canRead($reader, $discussion) ? $discussion : null;
            }

            $discussion = $discussions[$discussionId];
            if ($discussion instanceof Discussion && $post->isVisible()) {
                $readable[] = [$post, $discussion];
            }
        }

        $names = $this->accounts->namesOf($this->distinct(array_map(static fn (array $pair): ?string => $pair[0]->authorId(), $readable)));

        $sources = [];
        foreach ($readable as [$post, $discussion]) {
            $sources[$post->id()->value] = new QuoteSource(
                authorName: $post->authorId() === null ? null : ($names[$post->authorId()] ?? null),
                url: $this->links->urlFor($post, $discussion),
            );
        }

        return $sources;
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private function distinct(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (?string $value): bool => $value !== null)));
    }
}
