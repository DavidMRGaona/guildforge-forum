<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds\Providers;

use Modules\Forum\Application\Embeds\Contracts\EmbedProviderInterface;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * What providers share: reading a link's path and query, and checking ids against a pattern.
 */
abstract class AbstractEmbedProvider implements EmbedProviderInterface
{
    /** The pattern every id of the service matches, anchored */
    abstract protected function idPattern(): string;

    /** The id of a link already known to be on one of hosts() */
    abstract protected function idFromLink(RemoteUrl $url): ?string;

    public function idFromUrl(RemoteUrl $url): ?string
    {
        if (! $url->hostIsIn($this->hosts())) {
            return null;
        }

        $id = $this->idFromLink($url);

        return $id !== null && $this->isValidId($id) ? $id : null;
    }

    public function isValidId(string $id): bool
    {
        return strlen($id) <= 200 && preg_match($this->idPattern(), $id) === 1;
    }

    public function thumbnailHosts(): array
    {
        return [];
    }

    public function oembedUrl(string $id): ?RemoteUrl
    {
        return null;
    }

    public function detailsFrom(array $oembed): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function segments(RemoteUrl $url): array
    {
        $path = (string) strtok($url->pathAndQuery(), '?');

        return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function query(RemoteUrl $url): array
    {
        $mark = strpos($url->pathAndQuery(), '?');
        parse_str($mark === false ? '' : substr($url->pathAndQuery(), $mark + 1), $query);

        return $query;
    }

    protected function oembed(string $endpoint, string $pageUrl, string $extra = ''): ?RemoteUrl
    {
        return RemoteUrl::tryFrom($endpoint.(str_contains($endpoint, '?') ? '&' : '?').'url='.rawurlencode($pageUrl).$extra);
    }

    /** What the card details say, when it is a string */
    protected function detail(?EmbedCard $card, string $key): ?string
    {
        $value = $card?->details[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
