<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Conversations\BlockService;

/**
 * The people the person blocked, for the «Foro» tab of the profile, and blocking or unblocking
 * someone (JSON). Only one's own list: there is no way to read who blocked whom.
 */
final class BlocksController extends Controller
{
    use RespondsWithConversationErrors;

    public function __construct(
        private readonly BlockService $blocks,
        private readonly TimezoneServiceInterface $timezone,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['blocks' => array_map(fn (array $block): array => [
            'id' => $block['id'],
            'name' => $block['name'],
            'sinceLabel' => $this->timezone->format($block['at'], 'd/m/Y'),
            'unblockUrl' => route('forum.blocks.destroy', ['account' => $block['id']]),
        ], $this->blocks->blockedBy($user))]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $account = $request->json('account');

        return $this->respond(function () use ($user, $account): JsonResponse {
            $this->blocks->block($user, is_string($account) ? $account : '');

            return response()->json(['blocked' => true], 201);
        });
    }

    public function destroy(Request $request, string $account): JsonResponse
    {
        $this->blocks->unblock($this->user($request), $account);

        return response()->json(null, 204);
    }
}
