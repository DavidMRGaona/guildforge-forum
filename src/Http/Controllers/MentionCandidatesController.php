<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\AccountSummaryDTO;
use Modules\Forum\Application\Services\PostingService;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Whom the editor offers after «@»: people who may read the discussion, by name.
 */
final class MentionCandidatesController extends Controller
{
    use RespondsWithForumErrors;

    public const int MAX_QUERY_LENGTH = 50;

    public function __construct(
        private readonly PostingService $posting,
    ) {}

    public function index(Request $request, string $discussion): JsonResponse
    {
        return $this->respond(function () use ($request, $discussion): JsonResponse {
            $user = $request->user();
            abort_unless($user instanceof Authenticatable, 401);

            $query = $request->query('q');
            $query = is_string($query) ? mb_substr($query, 0, self::MAX_QUERY_LENGTH) : '';

            return response()->json(['data' => array_map(static fn (AccountSummaryDTO $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'avatarUrl' => $account->avatarUrl,
            ], $this->posting->mentionCandidates($user, DiscussionId::fromString($discussion), $query))]);
        });
    }
}
