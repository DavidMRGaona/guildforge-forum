<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\AuthorCards;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Http\Presenters\ForumPresenter;

/**
 * The card a post's author opens with (forum#134), as JSON: the core's profile card for the reader
 * and what the forum adds. Whoever may not see the post, or may not know who wrote it, gets what a
 * missing post gets, word for word. A card depends on who asks, so it is never stored by a cache.
 */
final class AuthorCardController extends Controller
{
    public function __construct(
        private readonly AuthorCards $cards,
        private readonly ForumUrlsInterface $urls,
    ) {}

    public function show(Request $request, string $post): JsonResponse
    {
        $user = $request->user();

        try {
            $card = $this->cards->of($user, PostId::fromString($post));
        } catch (PostNotFoundException) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        return response()->json([
            'profile' => $card->profile?->toArray(),
            'title' => $card->title,
            'postCount' => $card->postCount,
            'topic' => [
                'count' => $card->topicCount,
                'url' => $card->topicCount > 0
                    ? $this->urls->discussion($card->discussion).'?'.http_build_query([ForumPresenter::AUTHOR_QUERY => $card->authorId])
                    : null,
            ],
            'block' => $card->blocked === null ? null : [
                'blocked' => $card->blocked,
                'blockUrl' => route('forum.blocks.store', [], false),
                'unblockUrl' => route('forum.blocks.destroy', ['account' => $card->authorId], false),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
