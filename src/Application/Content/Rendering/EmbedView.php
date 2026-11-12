<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

use Modules\Forum\Application\Embeds\EmbedPlayer;

/**
 * An embedded post as a reader sees it: the card (its texts from the oEmbed data, the forum's
 * copy of its thumbnail) and, when its service is on, the player to load on request or with
 * consent to the service's cookie category.
 */
final readonly class EmbedView
{
    public function __construct(
        public ?string $title,
        public ?string $authorName,
        public ?string $thumbnailUrl,
        public ?EmbedPlayer $player,
        public ?string $consentCategory,
    ) {}

    public static function key(string $service, string $id): string
    {
        return "{$service} {$id}";
    }
}
