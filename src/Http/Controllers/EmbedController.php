<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Application\Embeds\EmbedProviders;
use Modules\Forum\Application\Services\EmbedCards;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Reads a link for the editor: the service and post it points to (among the services the
 * settings have on) and its card, which the server fetches once from the service's oEmbed
 * data. The browser never asks the service for anything.
 */
final class EmbedController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly EmbedProviders $providers,
        private readonly EmbedCards $cards,
        private readonly ForumSettingsInterface $settings,
        private readonly PostImageStorageInterface $images,
    ) {}

    public function store(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): JsonResponse {
            $address = $request->input('url');
            $url = is_string($address) && strlen($address) <= RemoteUrl::MAX_LENGTH ? RemoteUrl::tryFrom(trim($address)) : null;
            $found = $url instanceof RemoteUrl ? $this->providers->resolve($url, $this->settings->embedServices()) : null;
            if ($found === null) {
                throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_EMBED, '', 'Not a post of a service the forum embeds');
            }

            [$provider, $id] = $found;
            $card = $this->cards->cardFor($provider, $id);

            return response()->json([
                'service' => $provider->key(),
                'id' => $id,
                'label' => $provider->label(),
                'url' => $provider->url($id),
                'card' => [
                    'available' => $card->available,
                    'title' => $card->title,
                    'authorName' => $card->authorName,
                    'thumbnailUrl' => $card->thumbnailPath === null ? null : $this->images->url($card->thumbnailPath),
                ],
            ], 201);
        });
    }
}
