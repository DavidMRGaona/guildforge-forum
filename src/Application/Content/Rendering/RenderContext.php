<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

use Closure;

/**
 * What the renderer needs to know about the world outside the document, looked up in one go
 * for every post shown: the current names of mentioned accounts (so a rename shows at once),
 * the quoted posts the reader may see, the dice rolls, and where images are delivered from.
 * Each post adds who it speaks as (withSpeaker()): the name its rolls are thrown by.
 */
final readonly class RenderContext
{
    /**
     * @param  array<string, string>  $accountNames  by account id
     * @param  array<string, QuoteSource>  $quotes  by post id
     * @param  (Closure(string): ?string)|null  $imageUrl  the delivery URL of a stored image
     * @param  array<string, RollView>  $rolls  by roll id
     * @param  string|null  $speaker  the name of the character the post speaks as; null out of character
     */
    public function __construct(
        private array $accountNames = [],
        private array $quotes = [],
        private ?Closure $imageUrl = null,
        private array $rolls = [],
        private ?string $speaker = null,
    ) {}

    /**
     * The same context for a post that speaks as a character (its name), or out of character.
     */
    public function withSpeaker(?string $speaker): self
    {
        return new self($this->accountNames, $this->quotes, $this->imageUrl, $this->rolls, $speaker);
    }

    /** Who the text being rendered is said by: a character's name, or null out of character */
    public function speaker(): ?string
    {
        return $this->speaker;
    }

    public function accountName(string $accountId): ?string
    {
        return $this->accountNames[$accountId] ?? null;
    }

    public function quote(string $postId): ?QuoteSource
    {
        return $this->quotes[$postId] ?? null;
    }

    public function roll(string $rollId): ?RollView
    {
        return $this->rolls[$rollId] ?? null;
    }

    public function imageUrl(string $path): ?string
    {
        return $this->imageUrl instanceof Closure ? ($this->imageUrl)($path) : null;
    }
}
