<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Application\Embeds\Contracts\EmbedProviderInterface;
use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Application\Embeds\EmbedProviders;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * A post of another service (Reddit, X, YouTube…) shown in a post: only the service's key and
 * the id its provider reads and checks are kept, never HTML or an address. It is shown as the
 * forum's own card (texts escaped, the thumbnail from the forum's copy) with a link; the
 * service's player is loaded in the browser only when the reader asks for it, or consented to
 * the service's cookie category, so reading the forum tells the service nothing.
 */
final class EmbedNode extends AbstractNodeType
{
    private readonly EmbedProviders $providers;

    public function __construct(?EmbedProviders $providers = null)
    {
        $this->providers = $providers ?? EmbedProviders::builtIn();
    }

    public static function builtIn(): self
    {
        return new self;
    }

    public function name(): string
    {
        return 'embed';
    }

    public function isSubstantial(): bool
    {
        return true;
    }

    public function limit(): NodeLimit
    {
        return new NodeLimit(DocumentLimits::MAX_EMBEDS, InvalidDocumentException::TOO_MANY_EMBEDS);
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['service', 'id'], $path);

        if (! $this->providerOf($attributes) instanceof EmbedProviderInterface) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_EMBED, $path, 'Embeds name a known service and a post it reads');
        }
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        // Checked again: a stored document is never trusted to have been validated
        $provider = $this->providerOf($attributes);
        $id = Attributes::string($attributes, 'id');
        if (! $provider instanceof EmbedProviderInterface || $id === null) {
            return '';
        }

        $view = $context->embed($provider->key(), $id);
        $label = Html::escape($provider->label());
        $player = $view?->player;

        $figure = '<figure class="forum-embed" data-forum-embed data-embed-service="'.Html::escape($provider->key()).'"';
        if ($player instanceof EmbedPlayer) {
            $figure .= ' data-embed-src="'.Html::escape($player->src).'" data-embed-aspect="'.Html::escape($player->aspect).'"'
                .' data-embed-referrer="'.Html::escape($player->referrerPolicy).'"'
                .' data-embed-title="'.Html::escape(__('forum::messages.embeds.frame_title', ['service' => $provider->label()])).'"'
                .($view->consentCategory !== null ? ' data-embed-consent="'.Html::escape($view->consentCategory).'"' : '');
        }

        $card = '<div class="forum-embed__card">'
            .($view?->thumbnailUrl !== null ? '<img class="forum-embed__thumbnail" src="'.Html::escape($view->thumbnailUrl).'" alt="" loading="lazy" decoding="async">' : '')
            .'<div class="forum-embed__body">'
            .'<p class="forum-embed__service">'.$label.'</p>'
            .($view?->title !== null ? '<p class="forum-embed__title">'.Html::escape($view->title).'</p>' : '')
            .($view?->authorName !== null ? '<p class="forum-embed__author">'.Html::escape($view->authorName).'</p>' : '')
            .'<a class="forum-embed__link" href="'.Html::escape($provider->url($id)).'" target="_blank" rel="nofollow ugc noopener noreferrer" referrerpolicy="no-referrer">'
            .Html::escape(__('forum::messages.embeds.open', ['service' => $provider->label()])).'</a>'
            .'</div></div>';

        $load = $player instanceof EmbedPlayer ? '<div class="forum-embed__consent">'
            .'<button type="button" class="forum-embed__load" data-embed-load>'.Html::escape(__('forum::messages.embeds.load', ['service' => $provider->label()])).'</button>'
            .'<p class="forum-embed__notice">'.Html::escape(__('forum::messages.embeds.notice', ['service' => $provider->label()])).'</p>'
            .'</div>' : '';

        return $figure.'>'.$card.$load.'</figure>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        $provider = $this->providerOf($attributes);
        $id = Attributes::string($attributes, 'id');

        return $provider instanceof EmbedProviderInterface && $id !== null ? $provider->url($id) : '';
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function providerOf(array $attributes): ?EmbedProviderInterface
    {
        $service = $attributes['service'] ?? null;
        $id = $attributes['id'] ?? null;
        $provider = is_string($service) ? $this->providers->get($service) : null;

        return $provider instanceof EmbedProviderInterface && is_string($id) && $provider->isValidId($id) ? $provider : null;
    }
}
