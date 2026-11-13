<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * TikTok videos, «user/video id».
 */
final class TikTokProvider extends AbstractEmbedProvider
{
    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    public function hosts(): array
    {
        return ['tiktok.com'];
    }

    public function url(string $id): string
    {
        [$user, $video] = explode('/', $id, 2);

        return "https://www.tiktok.com/@{$user}/video/{$video}";
    }

    public function oembedUrl(string $id): ?RemoteUrl
    {
        return $this->oembed('https://www.tiktok.com/oembed', $this->url($id));
    }

    public function thumbnailHosts(): array
    {
        return ['tiktokcdn.com', 'tiktokcdn-us.com', 'tiktokcdn-eu.com'];
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): EmbedPlayer
    {
        [, $video] = explode('/', $id, 2);

        return new EmbedPlayer("https://www.tiktok.com/embed/v2/{$video}", EmbedPlayer::POST_SANDBOX, EmbedPlayer::PORTRAIT);
    }

    public function frameOrigins(): array
    {
        return ['https://www.tiktok.com'];
    }

    protected function idPattern(): string
    {
        return '#^[A-Za-z0-9._]{1,30}/\d{1,20}\z#';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        $segments = $this->segments($url);

        return str_starts_with($segments[0] ?? '', '@') && ($segments[1] ?? null) === 'video' && isset($segments[2])
            ? substr($segments[0], 1).'/'.$segments[2]
            : null;
    }
}
