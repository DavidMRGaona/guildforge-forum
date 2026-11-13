<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Content\Nodes\EmbedNode;
use Modules\Forum\Application\Content\Nodes\MentionNode;
use Modules\Forum\Application\Content\Nodes\PostQuoteNode;
use Modules\Forum\Application\Content\Nodes\RollNode;
use Modules\Forum\Application\Content\PostDocument;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Content\Rendering\QuoteSource;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Application\Content\Rendering\RollView;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Application\Contracts\PostLinksInterface;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Renders post bodies for a reader. Everything a page of posts needs from outside the
 * documents (mentioned names, quoted posts, image addresses) is looked up once for all of
 * them. A quote names its author and links to the original only when the reader may read that
 * post; otherwise it shows as a plain quotation.
 *
 * A hidden roll shows its result only to a reader HiddenRolls lets in. Plain text (stored with
 * the post, and from there in search, mail, notices and quotations) is written for nobody in
 * particular, so it never holds the result of a roll still secret.
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
        private DiceRollRepositoryInterface $diceRolls,
        private EmbedViews $embedViews,
        private CharacterRepositoryInterface $characters,
        private HiddenRolls $hiddenRolls,
    ) {}

    /**
     * @param  list<PostBody>  $bodies
     * @param  array<int, string|null>  $speakers  by the index of each body: the name of the character it speaks as (rolls say who throws them); missing or null out of character
     * @param  bool  $offerReveal  whether the page lets the reader reveal hidden rolls (not in old versions)
     * @return list<string> the HTML of each, in the same order
     */
    public function toHtml(array $bodies, ?Authenticatable $reader, array $speakers = [], bool $offerReveal = true): array
    {
        $context = $this->contextFor($bodies, $reader, $offerReveal);

        return array_map(
            fn (PostBody $body, int $index): string => $this->renderer->toHtml($body->document, $context->withSpeaker($speakers[$index] ?? null)),
            $bodies,
            array_keys($bodies),
        );
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
     * The plain text of a document whose rolls are settled, results included
     * («2d6+3 Ataque = 11») except those of rolls still hidden («Tirada oculta: 2d6+3 Ataque»).
     *
     * @param  array<string, mixed>  $document
     * @param  list<DiceRoll>  $rolls  the document's rolls
     */
    public function plainText(array $document, array $rolls): string
    {
        $mention = (new MentionNode)->name();
        $found = $this->renderer->attributesOf($document, [$mention]);
        $accountIds = array_map(static fn (array $attributes): ?string => AccountId::normalize(is_string($attributes['id'] ?? null) ? $attributes['id'] : ''), $found[$mention] ?? []);

        $views = [];
        foreach ($rolls as $roll) {
            $views[$roll->id] = RollView::of($roll);
        }

        return $this->renderer->toText($document, new RenderContext(accountNames: $this->accounts->namesOf($this->distinct($accountIds)), rolls: $views));
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
    private function contextFor(array $bodies, ?Authenticatable $reader, bool $offerReveal): RenderContext
    {
        $mention = (new MentionNode)->name();
        $quote = (new PostQuoteNode)->name();
        $roll = (new RollNode)->name();
        $embed = (new EmbedNode)->name();
        $accountIds = [];
        $postIds = [];
        $rollIds = [];
        $embeds = [];

        foreach ($bodies as $body) {
            $found = $this->renderer->attributesOf($body->document, [$mention, $quote, $roll, $embed]);
            foreach ($found[$embed] ?? [] as $attributes) {
                $embeds[] = $attributes;
            }
            foreach ($found[$mention] ?? [] as $attributes) {
                $accountIds[] = AccountId::normalize(is_string($attributes['id'] ?? null) ? $attributes['id'] : '');
            }
            foreach ($found[$quote] ?? [] as $attributes) {
                $postIds[] = AccountId::normalize(is_string($attributes['postId'] ?? null) ? $attributes['postId'] : '');
            }
            foreach ($found[$roll] ?? [] as $attributes) {
                $rollIds[] = is_string($attributes['rollId'] ?? null) ? $attributes['rollId'] : null;
            }
        }

        return new RenderContext(
            accountNames: $this->accounts->namesOf($this->distinct($accountIds)),
            quotes: $this->quoteSources($this->distinct($postIds), $reader),
            imageUrl: fn (string $path): ?string => $this->images->url($path),
            rolls: $this->rollViews($this->distinct($rollIds), $reader, $offerReveal),
            embeds: $this->embedViews->for($embeds),
        );
    }

    /**
     * @param  list<string>  $rollIds
     * @return array<string, RollView>
     */
    private function rollViews(array $rollIds, ?Authenticatable $reader, bool $offerReveal): array
    {
        return $rollIds === [] ? [] : $this->hiddenRolls->viewsFor($this->diceRolls->findMany($rollIds), $reader, $offerReveal);
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
        // A post that speaks as a shared character is quoted under its name, never its author's
        $shared = [];
        foreach ($this->characters->findMany(array_values(array_filter(array_map(static fn (array $pair): ?CharacterId => $pair[0]->characterId(), $readable)))) as $character) {
            if ($character->isNpc()) {
                $shared[$character->id()->value] = $character->name();
            }
        }

        $sources = [];
        foreach ($readable as [$post, $discussion]) {
            $sharedName = $shared[$post->characterId()->value ?? ''] ?? null;
            $sources[$post->id()->value] = new QuoteSource(
                authorName: $sharedName ?? ($post->authorId() === null ? null : ($names[$post->authorId()] ?? null)),
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
