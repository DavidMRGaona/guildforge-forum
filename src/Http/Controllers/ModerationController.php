<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\PostContentPresenter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * The moderation menus of a discussion and of each post, all JSON. What the person may not
 * read is not found (404), what they may not do is forbidden (403), something moderation never
 * does is 422 with its reason, and an action on something already in that state is 409.
 */
final class ModerationController extends Controller
{
    use RespondsWithForumErrors;

    private const array DISCUSSION_ACTIONS = ['pin', 'unpin', 'close', 'reopen', 'hide', 'show', 'rename', 'move'];

    private const array POST_ACTIONS = ['hide', 'show', 'approve', 'reject'];

    public function __construct(
        private readonly ForumModeration $moderation,
        private readonly DiscussionRepositoryInterface $discussions,
        private readonly CategoryRepositoryInterface $categories,
        private readonly PostContentPresenter $presenter,
        private readonly ForumUrlsInterface $urls,
        private readonly TimezoneServiceInterface $timezone,
    ) {}

    public function discussion(Request $request, string $discussion): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', 'string', Rule::in(self::DISCUSSION_ACTIONS)],
            'reason' => ['nullable', 'string', 'max:'.ModerationLogEntry::MAX_REASON_LENGTH],
            'title' => ['required_if:action,rename', 'nullable', 'string', 'max:500'],
            'category' => ['required_if:action,move', 'nullable', 'uuid'],
        ]);

        return $this->refusals(function () use ($request, $discussion, $input): JsonResponse {
            $user = $this->user($request);
            $id = DiscussionId::fromString($discussion);
            $reason = $this->text($input, 'reason');

            $changed = match ($input['action']) {
                'pin' => $this->moderation->pin($user, $id),
                'unpin' => $this->moderation->unpin($user, $id),
                'close' => $this->moderation->close($user, $id, $reason),
                'reopen' => $this->moderation->reopen($user, $id),
                'hide' => $this->moderation->hide($user, $id, $reason),
                'show' => $this->moderation->show($user, $id),
                'rename' => $this->moderation->rename($user, $id, (string) $this->text($input, 'title')),
                'move' => $this->moderation->move($user, $id, CategoryId::fromString((string) $this->text($input, 'category')), $reason),
                default => abort(422),
            };

            return response()->json(['discussion' => $this->presentDiscussion($changed)]);
        });
    }

    public function destroyDiscussion(Request $request, string $discussion): JsonResponse
    {
        $input = $request->validate([
            'reason' => ['nullable', 'string', 'max:'.ModerationLogEntry::MAX_REASON_LENGTH],
            // Deleting cannot be undone: the menu asks to tick a box first, and so does this
            'confirmed' => ['accepted'],
        ]);

        return $this->refusals(function () use ($request, $discussion, $input): JsonResponse {
            $user = $this->user($request);
            $id = DiscussionId::fromString($discussion);
            $category = $this->categories->find($this->moderation->moderated($user, $id)->categoryId());

            $this->moderation->delete($user, $id, $this->text($input, 'reason'));

            return response()->json(['url' => $category instanceof Category ? $this->urls->category($category) : $this->urls->index()]);
        });
    }

    public function post(Request $request, string $post): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', 'string', Rule::in(self::POST_ACTIONS)],
            'reason' => ['nullable', 'string', 'max:'.ModerationLogEntry::MAX_REASON_LENGTH],
        ]);

        return $this->refusals(function () use ($request, $post, $input): JsonResponse {
            $user = $this->user($request);
            $id = PostId::fromString($post);
            $reason = $this->text($input, 'reason');

            if ($input['action'] === 'reject') {
                $this->moderation->reject($user, $id, $reason);

                return response()->json(['removed' => true]);
            }

            $changed = match ($input['action']) {
                'hide' => $this->moderation->hidePost($user, $id, $reason),
                'show' => $this->moderation->showPost($user, $id),
                'approve' => $this->moderation->approve($user, $id),
                default => abort(422),
            };

            return response()->json(['post' => $this->presentPost($changed, $user)]);
        });
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function refusals(Closure $action): JsonResponse
    {
        return $this->respond(function () use ($action): JsonResponse {
            try {
                return $action();
            } catch (ModerationRefusedException $exception) {
                $message = __("forum::messages.moderation.errors.{$exception->reason}");

                return response()->json(['message' => $message, 'reason' => $exception->reason, 'errors' => ['moderation' => [$message]]], 422);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDiscussion(Discussion $discussion): array
    {
        return [
            'id' => $discussion->id()->value,
            'title' => $discussion->title()->value,
            'url' => $this->urls->discussion($discussion),
            'isPinned' => $discussion->isPinned(),
            'isClosed' => $discussion->status() === DiscussionStatus::Closed,
            'isHidden' => $discussion->isHidden(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPost(Post $post, Authenticatable $user): array
    {
        $discussion = $this->discussions->findOrFail($post->discussionId());
        [$content] = $this->presenter->present([$post], $discussion, $user);

        return PostController::toArray($content, $this->timezone);
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
