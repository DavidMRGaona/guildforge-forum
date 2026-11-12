<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Vimeo videos, played with Vimeo's do-not-track flag.
 */
final class VimeoProvider extends AbstractEmbedProvider
{
    public function key(): string
    {
        return 'vimeo';
    }

    public function label(): string
    {
        return 'Vimeo';
    }

    public function hosts(): array
    {
        return ['vimeo.com'];
    }

    public function url(string $id): string
    {
        return "https://vimeo.com/{$id}";
    }

    public function oembedUrl(string $id): ?RemoteUrl
    {
        return $this->oembed('https://vimeo.com/api/oembed.json', $this->url($id));
    }

    public function thumbnailHosts(): array
    {
        return ['vimeocdn.com'];
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): EmbedPlayer
    {
        return new EmbedPlayer("https://player.vimeo.com/video/{$id}?dnt=1", EmbedPlayer::VIDEO);
    }

    public function frameOrigins(): array
    {
        return ['https://player.vimeo.com'];
    }

    protected function idPattern(): string
    {
        return '/^\d{1,12}$/';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        // vimeo.com/123, vimeo.com/channels/staffpicks/123, player.vimeo.com/video/123
        $last = $this->segments($url);
        $id = end($last);

        return is_string($id) && ctype_digit($id) ? $id : null;
    }
}
