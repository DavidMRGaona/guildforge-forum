<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Services\FollowService;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * «Seguir / Normal / Silenciar» on a discussion and «Seguir» on a category, both JSON.
 */
final class FollowsController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly FollowService $follows,
        private readonly ForumBrowser $browser,
    ) {}

    public function discussion(Request $request, string $discussion): JsonResponse
    {
        $validated = $request->validate(['level' => ['required', 'string', Rule::enum(FollowLevel::class)]]);

        return $this->respond($this->orMergedAnswer($request, $discussion, function () use ($request, $discussion, $validated): JsonResponse {
            $level = $this->follows->setLevel($this->user($request), DiscussionId::fromString($discussion), FollowLevel::from((string) $validated['level']));

            return response()->json(['level' => $level->value]);
        }));
    }

    public function category(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate(['follow' => ['required', 'boolean']]);
        $user = $this->user($request);

        $access = $this->browser->findReadable($this->browser->readableCategories($user), $category);
        abort_unless($access instanceof CategoryAccessDTO, 404);

        $this->follows->setCategoryFollow($user, $access->category, (bool) $validated['follow']);

        return response()->json(['following' => $this->follows->followsCategory($user, $access->category)]);
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
