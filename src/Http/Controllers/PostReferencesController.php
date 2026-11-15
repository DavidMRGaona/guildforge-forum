<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\PostPreviewDTO;
use Modules\Forum\Application\Services\PostPreviews;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Http\Presenters\ForumPresenter;

/**
 * A post as a reference to it shows it, and the posts that reply to it. Whoever may not read a
 * post gets what a missing one gets, word for word.
 */
final class PostReferencesController extends Controller
{
    /** What a preview carries of the post as the discussion page draws it */
    private const array FIELDS = ['id', 'number', 'url', 'status', 'authorId', 'authorName', 'writerUnnamed', 'authorAvatarPublicId', 'authorTitle', 'character', 'publishedAt', 'publishedLabel', 'html', 'replyCount', 'repliesUrl'];

    public function __construct(
        private readonly PostPreviews $previews,
        private readonly ForumPresenter $presenter,
    ) {}

    public function preview(Request $request, string $post): JsonResponse
    {
        try {
            return response()->json($this->present($this->previews->preview($request->user(), PostId::fromString($post))));
        } catch (PostNotFoundException) {
            return $this->notFound();
        }
    }

    public function replies(Request $request, string $post): JsonResponse
    {
        $page = max(1, min(10_000, (int) $request->query('pagina', '1')));

        try {
            ['previews' => $previews, 'hasMore' => $hasMore] = $this->previews->replies($request->user(), PostId::fromString($post), $page);
        } catch (PostNotFoundException) {
            return $this->notFound();
        }

        return response()->json([
            'data' => array_map($this->present(...), $previews),
            'next' => $hasMore ? route('forum.posts.replies', ['post' => $post, 'pagina' => $page + 1]) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PostPreviewDTO $preview): array
    {
        $item = $this->presenter->posts($preview->discussion, [$preview->post], [$preview->content])['posts'][0];

        return [
            ...array_intersect_key($item, array_flip(self::FIELDS)),
            'discussionTitle' => $preview->discussion->title()->value,
            'truncated' => $preview->truncated,
            'hiddenImages' => $preview->hiddenImages,
            'hiddenEmbeds' => $preview->hiddenEmbeds,
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }
}
