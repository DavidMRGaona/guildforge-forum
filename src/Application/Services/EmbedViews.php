<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Application\Content\Rendering\EmbedView;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Application\Embeds\Contracts\EmbedProviderInterface;
use Modules\Forum\Application\Embeds\EmbedProviders;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\Repositories\EmbedCardRepositoryInterface;

/**
 * What readers are shown of the embeds of a page of posts, looked up in one go: the stored
 * cards, the thumbnails' delivery addresses and, for services the settings have on, the
 * player and the cookie category whose consent loads it without a click. Nothing is fetched
 * here: a post without a card yet shows its link.
 */
final readonly class EmbedViews
{
    public function __construct(
        private EmbedProviders $providers,
        private EmbedCardRepositoryInterface $cards,
        private PostImageStorageInterface $images,
        private ForumSettingsInterface $settings,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $attributes  of the embed nodes
     * @return array<string, EmbedView> by EmbedView::key()
     */
    public function for(array $attributes): array
    {
        $wanted = [];
        foreach ($attributes as $attribute) {
            $service = $attribute['service'] ?? null;
            $id = $attribute['id'] ?? null;
            if (is_string($service) && is_string($id) && $this->providers->get($service)?->isValidId($id) === true) {
                $wanted[EmbedView::key($service, $id)] = [$service, $id];
            }
        }
        if ($wanted === []) {
            return [];
        }

        $cards = [];
        foreach ($this->cards->findMany(array_values($wanted)) as $card) {
            $cards[EmbedView::key($card->service, $card->embedId)] = $card;
        }

        $enabled = $this->settings->embedServices();
        $siteHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $views = [];
        foreach ($wanted as $key => [$service, $id]) {
            $provider = $this->providers->get($service);
            if (! $provider instanceof EmbedProviderInterface) {
                continue;
            }
            $card = $cards[$key] ?? null;
            $on = in_array($service, $enabled, true);

            $views[$key] = new EmbedView(
                $card?->title,
                $card?->authorName,
                $this->thumbnailUrl($card),
                $on ? $provider->player($id, $card, $siteHost) : null,
                $on ? $this->settings->embedCookieCategory($service) : null,
            );
        }

        return $views;
    }

    private function thumbnailUrl(?EmbedCard $card): ?string
    {
        return $card?->thumbnailPath === null ? null : $this->images->url($card->thumbnailPath);
    }
}
