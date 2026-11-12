<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Twitch videos («v:123»), clips («c:Slug») and channels («ch:name»). Twitch gives no oEmbed
 * without a key, so the card is the link; its player checks the site it is embedded in.
 */
final class TwitchProvider extends AbstractEmbedProvider
{
    /** Twitch pages whose first segment is not a channel */
    private const array RESERVED = ['directory', 'videos', 'settings', 'search', 'downloads', 'jobs', 'p', 'subscriptions', 'inventory', 'wallet', 'drops', 'friends', 'messages', 'turbo', 'prime'];

    public function key(): string
    {
        return 'twitch';
    }

    public function label(): string
    {
        return 'Twitch';
    }

    public function hosts(): array
    {
        return ['twitch.tv'];
    }

    public function url(string $id): string
    {
        [$kind, $value] = explode(':', $id, 2);

        return match ($kind) {
            'v' => "https://www.twitch.tv/videos/{$value}",
            'c' => "https://clips.twitch.tv/{$value}",
            default => "https://www.twitch.tv/{$value}",
        };
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): EmbedPlayer
    {
        [$kind, $value] = explode(':', $id, 2);
        $parent = rawurlencode($siteHost);

        return new EmbedPlayer(match ($kind) {
            'v' => "https://player.twitch.tv/?video=v{$value}&parent={$parent}&autoplay=false",
            'c' => "https://clips.twitch.tv/embed?clip={$value}&parent={$parent}&autoplay=false",
            default => "https://player.twitch.tv/?channel={$value}&parent={$parent}&autoplay=false",
        }, EmbedPlayer::VIDEO);
    }

    public function frameOrigins(): array
    {
        return ['https://player.twitch.tv', 'https://clips.twitch.tv'];
    }

    protected function idPattern(): string
    {
        return '/^(v:\d{1,12}|c:[A-Za-z0-9_-]{1,100}|ch:[a-z0-9_]{3,25})$/';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        $segments = $this->segments($url);

        if ($url->host === 'clips.twitch.tv') {
            return isset($segments[0]) && $segments[0] !== 'embed' ? "c:{$segments[0]}" : null;
        }
        if (($segments[0] ?? null) === 'videos' && isset($segments[1])) {
            return "v:{$segments[1]}";
        }
        if (($segments[1] ?? null) === 'clip' && isset($segments[2])) {
            return "c:{$segments[2]}";
        }
        if (count($segments) === 1 && ! in_array(strtolower($segments[0]), self::RESERVED, true)) {
            return 'ch:'.strtolower($segments[0]);
        }

        return null;
    }
}
