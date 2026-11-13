<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Conversations\ConversationReportService;
use Modules\Forum\Application\Conversations\ConversationService;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Http\Presenters\ConversationPresenter;

/**
 * Writing a message in a private conversation, and deleting one's own (JSON). Both answer the
 * entry as the page shows it.
 */
final class ConversationMessageController extends Controller
{
    use RespondsWithConversationErrors;

    public function __construct(
        private readonly ConversationService $service,
        private readonly ConversationReportService $reports,
        private readonly ConversationPresenter $presenter,
    ) {}

    public function store(Request $request, string $conversation): JsonResponse
    {
        $user = $this->user($request);

        return $this->respond(function () use ($request, $user, $conversation): JsonResponse {
            $id = ConversationId::fromString(strtolower($conversation));
            $message = $this->service->reply($user, $id, $this->documentFrom($request));

            return response()->json(['entry' => $this->presenter->one($message, $user, $this->reports->mayReportIn($user, $id))], 201);
        });
    }

    public function destroy(Request $request, string $message): JsonResponse
    {
        $user = $this->user($request);

        return $this->respond(function () use ($user, $message): JsonResponse {
            $deleted = $this->service->deleteOwn($user, ConversationMessageId::fromString(strtolower($message)));

            return response()->json(['entry' => $this->presenter->one($deleted, $user, false)]);
        });
    }
}
