<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Application\Services\PostContentPresenter;
use Modules\Forum\Application\Services\PostingService;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Replying, editing and deleting from the editor. The body is `{"document": <editor JSON>}`;
 * the answer is the post as the author now sees it.
 */
final class PostController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly PostingService $posting,
        private readonly PostContentPresenter $presenter,
        private readonly DiscussionRepositoryInterface $discussions,
        private readonly TimezoneServiceInterface $timezone,
    ) {}

    public function store(Request $request, string $discussion): JsonResponse
    {
        return $this->respond(function () use ($request, $discussion): JsonResponse {
            $user = $this->user($request);
            $post = $this->posting->reply($user, DiscussionId::fromString($discussion), $this->documentFrom($request));

            return response()->json(['post' => $this->present($post, $user)], 201);
        });
    }

    public function update(Request $request, string $post): JsonResponse
    {
        return $this->respond(function () use ($request, $post): JsonResponse {
            $user = $this->user($request);
            $edited = $this->posting->edit($user, PostId::fromString($post), $this->documentFrom($request));

            return response()->json(['post' => $this->present($edited, $user)]);
        });
    }

    public function destroy(Request $request, string $post): JsonResponse
    {
        return $this->respond(function () use ($request, $post): JsonResponse {
            $user = $this->user($request);
            $deleted = $this->posting->deleteOwn($user, PostId::fromString($post));

            return response()->json(['post' => $this->present($deleted, $user)]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Post $post, Authenticatable $user): array
    {
        $discussion = $this->discussions->findOrFail($post->discussionId());
        [$content] = $this->presenter->present([$post], $discussion, $user);

        return self::toArray($content, $this->timezone);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(PostContentDTO $content, TimezoneServiceInterface $timezone): array
    {
        return [
            'id' => $content->id,
            'number' => $content->number,
            'authorId' => $content->authorId,
            'status' => $content->status->value,
            'html' => $content->html,
            'placeholder' => $content->placeholder,
            'editedAt' => $timezone->iso($content->editedAt),
            'canEdit' => $content->canEdit,
            'canDelete' => $content->canDelete,
        ];
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
