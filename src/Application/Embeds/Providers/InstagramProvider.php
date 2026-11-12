<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Instagram posts and reels, «p/code» or «reel/code». Instagram gives no oEmbed without a key,
 * so the card is the link.
 */
final class InstagramProvider extends AbstractEmbedProvider
{
    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    public function hosts(): array
    {
        return ['instagram.com'];
    }

    public function url(string $id): string
    {
        return "https://www.instagram.com/{$id}/";
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): EmbedPlayer
    {
        return new EmbedPlayer("https://www.instagram.com/{$id}/embed/", EmbedPlayer::PORTRAIT);
    }

    public function frameOrigins(): array
    {
        return ['https://www.instagram.com'];
    }

    protected function idPattern(): string
    {
        return '#^(p|reel)/[A-Za-z0-9_-]{5,40}$#';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        // /p/code, /reel/code, /tv/code, /user/p/code
        $segments = $this->segments($url);
        foreach ($segments as $index => $segment) {
            if (in_array($segment, ['p', 'reel', 'tv'], true) && isset($segments[$index + 1])) {
                return ($segment === 'p' ? 'p' : 'reel').'/'.$segments[$index + 1];
            }
        }

        return null;
    }
}
