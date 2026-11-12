<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * YouTube videos (watch, youtu.be, shorts, live, embed links), played from youtube-nocookie.com.
 * The player refuses to load without a referrer, so it gets the site's origin only, never the
 * page.
 */
final class YouTubeProvider extends AbstractEmbedProvider
{
    public function key(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function hosts(): array
    {
        return ['youtube.com', 'youtu.be'];
    }

    public function url(string $id): string
    {
        return "https://www.youtube.com/watch?v={$id}";
    }

    public function oembedUrl(string $id): ?RemoteUrl
    {
        return $this->oembed('https://www.youtube.com/oembed?format=json', $this->url($id));
    }

    public function thumbnailHosts(): array
    {
        return ['ytimg.com'];
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): EmbedPlayer
    {
        return new EmbedPlayer("https://www.youtube-nocookie.com/embed/{$id}?rel=0", [...EmbedPlayer::POST_SANDBOX, EmbedPlayer::PRESENTATION], EmbedPlayer::VIDEO, EmbedPlayer::ORIGIN_ONLY);
    }

    public function frameOrigins(): array
    {
        return ['https://www.youtube-nocookie.com'];
    }

    protected function idPattern(): string
    {
        return '/^[A-Za-z0-9_-]{11}$/';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        $segments = $this->segments($url);

        if ($url->host === 'youtu.be') {
            return $segments[0] ?? null;
        }

        if (($segments[0] ?? null) === 'watch') {
            $v = $this->query($url)['v'] ?? null;

            return is_string($v) ? $v : null;
        }

        return in_array($segments[0] ?? null, ['shorts', 'live', 'embed'], true) ? ($segments[1] ?? null) : null;
    }
}
