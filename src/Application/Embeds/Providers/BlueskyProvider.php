<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Bluesky posts, «handle/record key». Its player needs the account's DID, which a handle link
 * does not carry: it comes from the oEmbed data, so without it there is only the card.
 */
final class BlueskyProvider extends AbstractEmbedProvider
{
    public const string DID = 'did';

    public const string DID_PATTERN = '/^did:(plc:[a-z2-7]{24}|web:[a-z0-9.-]{1,200})\z/';

    public function key(): string
    {
        return 'bluesky';
    }

    public function label(): string
    {
        return 'Bluesky';
    }

    public function hosts(): array
    {
        return ['bsky.app'];
    }

    public function url(string $id): string
    {
        [$handle, $post] = explode('/', $id, 2);

        return "https://bsky.app/profile/{$handle}/post/{$post}";
    }

    public function oembedUrl(string $id): ?RemoteUrl
    {
        return $this->oembed('https://embed.bsky.app/oembed', $this->url($id));
    }

    /**
     * The account's DID, from the post's AT URI in the oEmbed markup (read, never kept).
     */
    public function detailsFrom(array $oembed): array
    {
        $html = $oembed['html'] ?? null;

        return is_string($html) && preg_match('#at://(did:(?:plc:[a-z2-7]{24}|web:[a-z0-9.-]{1,200}))/app\.bsky\.feed\.post/#', $html, $match) === 1
            ? [self::DID => $match[1]]
            : [];
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): ?EmbedPlayer
    {
        [$handle, $post] = explode('/', $id, 2);
        $did = preg_match(self::DID_PATTERN, $handle) === 1 ? $handle : $this->detail($card, self::DID);

        return $did !== null && preg_match(self::DID_PATTERN, $did) === 1
            ? new EmbedPlayer("https://embed.bsky.app/embed/{$did}/app.bsky.feed.post/{$post}", EmbedPlayer::POST_SANDBOX, EmbedPlayer::POST)
            : null;
    }

    public function frameOrigins(): array
    {
        return ['https://embed.bsky.app'];
    }

    protected function idPattern(): string
    {
        return '#^([a-z0-9-]+(\.[a-z0-9-]+)+|did:plc:[a-z2-7]{24})/[a-z2-7]{13}$#';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        $segments = $this->segments($url);

        return ($segments[0] ?? null) === 'profile' && ($segments[2] ?? null) === 'post' && isset($segments[3])
            ? strtolower(rawurldecode($segments[1])).'/'.$segments[3]
            : null;
    }
}
