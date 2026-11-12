<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Reddit posts, «subreddit/post id».
 */
final class RedditProvider extends AbstractEmbedProvider
{
    public function key(): string
    {
        return 'reddit';
    }

    public function label(): string
    {
        return 'Reddit';
    }

    public function hosts(): array
    {
        return ['reddit.com'];
    }

    public function url(string $id): string
    {
        [$subreddit, $post] = explode('/', $id, 2);

        return "https://www.reddit.com/r/{$subreddit}/comments/{$post}/";
    }

    public function oembedUrl(string $id): ?RemoteUrl
    {
        return $this->oembed('https://www.reddit.com/oembed', $this->url($id));
    }

    public function player(string $id, ?EmbedCard $card, string $siteHost): EmbedPlayer
    {
        [$subreddit, $post] = explode('/', $id, 2);

        return new EmbedPlayer("https://embed.reddit.com/r/{$subreddit}/comments/{$post}/?embed=true&showmedia=true", EmbedPlayer::POST_SANDBOX, EmbedPlayer::POST);
    }

    public function frameOrigins(): array
    {
        return ['https://embed.reddit.com'];
    }

    protected function idPattern(): string
    {
        return '#^[A-Za-z0-9_]{2,21}/[a-z0-9]{1,12}$#';
    }

    protected function idFromLink(RemoteUrl $url): ?string
    {
        $segments = $this->segments($url);

        return ($segments[0] ?? null) === 'r' && ($segments[2] ?? null) === 'comments' && isset($segments[3])
            ? "{$segments[1]}/".strtolower($segments[3])
            : null;
    }
}
