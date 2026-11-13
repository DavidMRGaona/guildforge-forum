<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Services\PostContentPresenter;
use Modules\Forum\Application\Services\RollReveals;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;

/**
 * Revealing a hidden roll: whoever threw it or the campaign's direction. The answer is the post
 * as the person now sees it, as the editor's endpoints answer.
 */
final class RollController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly RollReveals $reveals,
        private readonly PostContentPresenter $presenter,
        private readonly DiscussionRepositoryInterface $discussions,
        private readonly TimezoneServiceInterface $timezone,
    ) {}

    public function reveal(Request $request, string $roll): JsonResponse
    {
        return $this->respond(function () use ($request, $roll): JsonResponse {
            $user = $request->user();
            abort_unless($user instanceof Authenticatable, 401);

            $post = $this->reveals->reveal($user, strtolower($roll));
            [$content] = $this->presenter->present([$post], $this->discussions->findOrFail($post->discussionId()), $user);

            return response()->json(['post' => PostController::toArray($content, $this->timezone)]);
        });
    }
}
