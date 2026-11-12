<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Posts on X (formerly Twitter), by their numeric id, shown with X's do-not-track flag in its
 * own frame (never its widgets script on the forum's page).
 */
final class XProvider extends AbstractEmbedProvider
{
    public function key(): string
    {
        return 'x';
    }

    public function label(): string
    {
        return 'X';
    }

    public function hosts(): array
    {
        return ['x.com', 'twitter.com'];
    }

    public function url(string $id): string
    {
        return "https://x.com/i/status/{$id}";
    }

    public function oembedUrl(string $id): ?RemoteUrl
    {
        return $this->oembed('https://publish.twitter.com/oembed?omit_script=1&dnt=true', "https://twitter.com/i/status/{$id}");
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): EmbedPlayer
    {
        return new EmbedPlayer("https://platform.twitter.com/embed/Tweet.html?id={$id}&dnt=true", EmbedPlayer::POST_SANDBOX, EmbedPlayer::POST);
    }

    public function frameOrigins(): array
    {
        return ['https://platform.twitter.com'];
    }

    protected function idPattern(): string
    {
        return '/^\d{1,20}$/';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        // /user/status/123, /i/status/123, /i/web/status/123
        $segments = $this->segments($url);
        $at = array_search('status', $segments, true);

        return is_int($at) && $at > 0 && isset($segments[$at + 1]) ? $segments[$at + 1] : null;
    }
}
