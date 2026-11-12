<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Contracts;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * A service whose posts can be embedded in a forum post. The node keeps only the service's key
 * and an id this provider reads from a link and checks; everything else (the page address, the
 * oEmbed endpoint, the player) is built here from that id, never taken from the document.
 */
interface EmbedProviderInterface
{
    /** Stored in the node: lowercase letters only */
    public function key(): string;

    /** The service's name, as people know it */
    public function label(): string;

    /**
     * The sites whose links it reads (each with its subdomains), so the editor can offer to
     * embed a pasted link.
     *
     * @return list<string>
     */
    public function hosts(): array;

    /** The id of the post a link points to, or null when it is not a link to one */
    public function idFromUrl(RemoteUrl $url): ?string;

    public function isValidId(string $id): bool;

    /** The post's page on the service */
    public function url(string $id): string;

    /** Where its oEmbed data is, or null when the service gives none without a key */
    public function oembedUrl(string $id): ?RemoteUrl;

    /**
     * The sites its oEmbed thumbnails may come from (each with its subdomains); others are not
     * fetched.
     *
     * @return list<string>
     */
    public function thumbnailHosts(): array;

    /**
     * What its player needs beyond the id, read from its oEmbed data (never kept as HTML).
     *
     * @param  array<array-key, mixed>  $oembed
     * @return array<string, string>
     */
    public function detailsFrom(array $oembed): array;

    /**
     * Its player or widget, loaded only when the reader asks for it or consented to its cookie
     * category; null when it cannot be built (a detail the oEmbed data did not give).
     *
     * @param  string  $siteHost  the forum's host, for services that check where they are embedded
     */
    public function player(string $id, ?EmbedCard $card, string $siteHost): ?EmbedPlayer;

    /**
     * Origins its players are served from (for the page's frame-src).
     *
     * @return list<string>
     */
    public function frameOrigins(): array;
}
