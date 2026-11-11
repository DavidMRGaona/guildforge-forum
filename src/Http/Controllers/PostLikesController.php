<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\PostLikesDTO;
use Modules\Forum\Application\Services\PostLikeService;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Http\Presenters\ForumPresenter;

/**
 * «Me gusta» on a post (POST) and taking it back (DELETE), both answering the post's likes as
 * the page shows them.
 */
final class PostLikesController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly PostLikeService $likes,
    ) {}

    public function store(Request $request, string $post): JsonResponse
    {
        return $this->respond(fn (): JsonResponse => $this->answer($this->likes->like($this->user($request), PostId::fromString($post))));
    }

    public function destroy(Request $request, string $post): JsonResponse
    {
        return $this->respond(fn (): JsonResponse => $this->answer($this->likes->unlike($this->user($request), PostId::fromString($post))));
    }

    private function answer(PostLikesDTO $likes): JsonResponse
    {
        return response()->json(['likes' => ForumPresenter::likes($likes, $this->likes->namesOf(['post' => $likes]))]);
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
