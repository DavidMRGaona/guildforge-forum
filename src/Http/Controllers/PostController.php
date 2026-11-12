<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\PostContentDTO;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\PostBodyFactory;
use Modules\Forum\Application\Services\PostContentPresenter;
use Modules\Forum\Application\Services\PostingService;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Http\Presenters\MergedDiscussionAnswer;

/**
 * Replying, editing and deleting from the editor (moderators edit other people's posts too). The body is
 * `{"document": <editor JSON>, "character": <id> | null}` (no `character` on an edit keeps who it speaks
 * as); the answer is the post as the author now sees it.
 */
final class PostController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly PostingService $posting,
        private readonly PostContentPresenter $presenter,
        private readonly DiscussionRepositoryInterface $discussions,
        private readonly TimezoneServiceInterface $timezone,
        private readonly ForumModeration $moderation,
        private readonly PostBodyFactory $bodies,
        private readonly PostRepositoryInterface $posts,
        private readonly MergedDiscussionAnswer $merged,
    ) {}

    public function store(Request $request, string $discussion): JsonResponse
    {
        return $this->respond(function () use ($request, $discussion): JsonResponse {
            $user = $this->user($request);
            [, $characterId] = $this->speakerFrom($request);

            try {
                $post = $this->posting->reply($user, DiscussionId::fromString($discussion), $this->documentFrom($request), $characterId);
            } catch (DiscussionNotFoundException $exception) {
                // A page left open on a discussion that was merged: say where it went (410)
                return $this->merged->for($user, $discussion) ?? throw $exception;
            }

            return response()->json(['post' => $this->present($post, $user)], 201);
        });
    }

    public function update(Request $request, string $post): JsonResponse
    {
        return $this->respond(function () use ($request, $post): JsonResponse {
            $user = $this->user($request);
            $id = PostId::fromString($post);
            $document = $this->documentFrom($request);
            [$speakerGiven, $characterId] = $this->speakerFrom($request);

            try {
                if ($this->posting->owns($user, $id)) {
                    return response()->json(['post' => $this->present($this->posting->edit($user, $id, $document, $speakerGiven, $characterId), $user)]);
                }
            } catch (ForumActionDeniedException) {
                // Their own post where they may no longer post: only as its moderator, below
            }

            // Someone else's post is edited only by its moderators, and the edit is logged; it
            // keeps speaking as it did (nobody chooses someone else's character)
            // (images from other sites it already had are kept without the moderator's permission)
            $edited = $this->moderation->editPost($user, $id, $this->bodies->fromInput($document, $user, $this->posts->find($id)?->body()));

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
            'hiddenReason' => $content->hiddenReason,
        ];
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
