<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Contracts\RemoteFetcherInterface;
use Modules\Forum\Application\Embeds\Contracts\EmbedProviderInterface;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\Repositories\EmbedCardRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use RuntimeException;

/**
 * The card of an embedded post, fetched once by the server from the service's oEmbed data,
 * with the same guards as images from other sites. Only plain texts are kept (the oEmbed HTML
 * is read for a text excerpt or a player detail, never stored or shown), and the thumbnail
 * only from the service's own hosts, as a copy of the forum. A post the service does not give
 * (deleted, private, the oEmbed failing) is kept as unavailable and tried again a day later.
 */
final readonly class EmbedCards
{
    public const int RETRY_AFTER_HOURS = 24;

    public const int MAX_OEMBED_BYTES = 512 * 1024;

    public function __construct(
        private EmbedCardRepositoryInterface $cards,
        private RemoteFetcherInterface $fetcher,
        private ExternalImageImporter $images,
    ) {}

    public function cardFor(EmbedProviderInterface $provider, string $id): EmbedCard
    {
        $stored = $this->cards->find($provider->key(), $id);
        if ($stored instanceof EmbedCard && ($stored->available || $stored->fetchedAt->greaterThan(CarbonImmutable::now()->subHours(self::RETRY_AFTER_HOURS)))) {
            return $stored;
        }

        $card = $this->fetch($provider, $id);
        $this->cards->save($card);

        return $card;
    }

    private function fetch(EmbedProviderInterface $provider, string $id): EmbedCard
    {
        $now = CarbonImmutable::now();
        $endpoint = $provider->oembedUrl($id);
        if (! $endpoint instanceof RemoteUrl) {
            // A service without oEmbed: the card is its name and the link
            return new EmbedCard($provider->key(), $id, true, null, null, null, [], $now);
        }

        try {
            $data = json_decode($this->fetcher->fetch($endpoint, self::MAX_OEMBED_BYTES, 'application/json')->bytes, true);
        } catch (RemoteFetchException $exception) {
            Log::info('Forum: the oEmbed data of an embedded post was not fetched.', ['service' => $provider->key(), 'reason' => $exception->reason]);

            return EmbedCard::unavailable($provider->key(), $id, $now);
        }

        if (! is_array($data)) {
            return EmbedCard::unavailable($provider->key(), $id, $now);
        }

        return new EmbedCard(
            $provider->key(),
            $id,
            true,
            $this->text($data['title'] ?? null, EmbedCard::MAX_TITLE_LENGTH) ?? $this->text($this->excerpt($data['html'] ?? null), EmbedCard::MAX_TITLE_LENGTH),
            $this->text($data['author_name'] ?? null, EmbedCard::MAX_AUTHOR_LENGTH),
            $this->thumbnail($provider, $data['thumbnail_url'] ?? null),
            $provider->detailsFrom($data),
            $now,
        );
    }

    /**
     * The forum's copy of the thumbnail, when it comes from the service's own hosts.
     */
    private function thumbnail(EmbedProviderInterface $provider, mixed $address): ?string
    {
        $url = is_string($address) ? RemoteUrl::tryFrom($address) : null;
        if (! $url instanceof RemoteUrl || ! $url->hostIsIn($provider->thumbnailHosts())) {
            return null;
        }

        try {
            return $this->images->copyOf($url);
        } catch (InvalidDocumentException|RuntimeException $exception) {
            Log::info('Forum: the thumbnail of an embedded post was not copied.', ['service' => $provider->key(), 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * The text of the first paragraph of an oEmbed markup, without its tags: a post on X or
     * Bluesky has no title, only its text.
     */
    private function excerpt(mixed $html): ?string
    {
        if (! is_string($html) || preg_match('#<p[^>]*>(.*?)</p>#is', $html, $match) !== 1) {
            return null;
        }

        return html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A single line of plain text of at most the given characters, or null when empty.
     */
    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $line = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $value));
        if ($line === '') {
            return null;
        }

        return mb_strlen($line) > $maxLength ? rtrim(mb_substr($line, 0, $maxLength - 1)).'…' : $line;
    }
}
