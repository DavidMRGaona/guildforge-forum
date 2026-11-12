<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use Carbon\CarbonImmutable;

/**
 * What the forum keeps of an embedded post, fetched once by the server from the service's
 * oEmbed data: plain texts (never its HTML) and the forum's copy of its thumbnail. A post the
 * service no longer gives (deleted, private, the oEmbed failing) is kept as unavailable, and
 * readers see the card with its link.
 */
final readonly class EmbedCard
{
    public const int MAX_TITLE_LENGTH = 300;

    public const int MAX_AUTHOR_LENGTH = 120;

    /**
     * @param  array<string, string>  $details  what a player needs beyond the id (Bluesky's DID)
     */
    public function __construct(
        public string $service,
        public string $embedId,
        public bool $available,
        public ?string $title,
        public ?string $authorName,
        public ?string $thumbnailPath,
        public array $details,
        public CarbonImmutable $fetchedAt,
    ) {}

    public static function unavailable(string $service, string $embedId, CarbonImmutable $at): self
    {
        return new self($service, $embedId, false, null, null, null, [], $at);
    }
}
