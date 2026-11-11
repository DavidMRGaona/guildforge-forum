<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\DTOs\PostVersionDTO;
use Modules\Forum\Application\Services\PostingService;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * A post's versions, oldest first, for its moderators.
 */
final class PostVersionsController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly PostingService $posting,
        private readonly TimezoneServiceInterface $timezone,
    ) {}

    public function index(Request $request, string $post): JsonResponse
    {
        return $this->respond(function () use ($request, $post): JsonResponse {
            $user = $request->user();
            abort_unless($user instanceof Authenticatable, 401);

            return response()->json(['data' => array_map(fn (PostVersionDTO $version): array => [
                'number' => $version->number,
                'html' => $version->html,
                'authorName' => $version->authorName,
                'writtenAt' => $this->timezone->iso($version->writtenAt),
                'isCurrent' => $version->isCurrent,
            ], $this->posting->versions($user, PostId::fromString($post)))]);
        });
    }
}
