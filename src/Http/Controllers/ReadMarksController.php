<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Services\ConversationReader;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Read marks: the discussion page reports the highest post it showed (JSON), and «Marcar todo
 * como leído» marks a category (with its subcategories) or the whole forum.
 */
final class ReadMarksController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly ConversationReader $reader,
        private readonly ForumBrowser $browser,
    ) {}

    public function discussion(Request $request, string $discussion): JsonResponse
    {
        $validated = $request->validate(['number' => ['required', 'integer', 'min:1', 'max:4294967295']]);

        return $this->respond(function () use ($request, $discussion, $validated): JsonResponse {
            $this->reader->markRead($this->user($request), DiscussionId::fromString($discussion), (int) $validated['number'], CarbonImmutable::now());

            return response()->json(['read' => true]);
        });
    }

    public function forum(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->browser->markRead($user, $this->browser->readableCategories($user), CarbonImmutable::now());

        return back()->with('success', __('forum::messages.reads.forum_marked'));
    }

    public function category(Request $request, string $category): RedirectResponse
    {
        $user = $this->user($request);
        $readable = $this->browser->readableCategories($user);
        $access = $this->browser->findReadable($readable, $category);
        abort_unless($access instanceof CategoryAccessDTO, 404);

        $this->browser->markRead($user, $this->browser->withSubcategories($readable, $access), CarbonImmutable::now());

        return back()->with('success', __('forum::messages.reads.category_marked', ['name' => $access->category->name()]));
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
