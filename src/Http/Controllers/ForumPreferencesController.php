<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Services\FollowedThings;
use Modules\Forum\Application\Services\FollowService;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * The «Foro» tab of the profile: whether posting follows a discussion, and the categories and
 * discussions the person follows, each with the address that stops following it.
 */
final class ForumPreferencesController extends Controller
{
    public function __construct(
        private readonly FollowService $follows,
        private readonly FollowedThings $followed,
        private readonly ForumBrowser $browser,
        private readonly ForumUrlsInterface $urls,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $readable = $this->browser->readableCategories($user);

        return response()->json([
            'autoFollow' => $this->follows->autoFollows($user),
            'updateUrl' => route('forum.preferences.update'),
            'categories' => array_map(fn (CategoryAccessDTO $access): array => [
                'id' => $access->category->id()->value,
                'name' => $access->category->name(),
                'parentName' => ($parentId = $access->category->parentId()) instanceof CategoryId ? ForumBrowser::categoryById($readable, $parentId->value)?->name() : null,
                'url' => $this->urls->category($access->category),
                'followUrl' => route('forum.categories.follow', ['category' => $access->category->slug()->value]),
            ], $this->followed->categories($user)),
            'discussions' => array_map(fn (Discussion $discussion): array => [
                'id' => $discussion->id()->value,
                'title' => $discussion->title()->value,
                'url' => $this->urls->discussion($discussion),
                'followUrl' => route('forum.discussions.follow', ['discussion' => $discussion->id()->value]),
            ], $this->followed->discussions($user)),
            'forumUrl' => $this->urls->index(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['autoFollow' => ['required', 'boolean']]);
        $user = $this->user($request);

        $this->follows->setAutoFollows($user, (bool) $validated['autoFollow']);

        return response()->json(['autoFollow' => $this->follows->autoFollows($user)]);
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
