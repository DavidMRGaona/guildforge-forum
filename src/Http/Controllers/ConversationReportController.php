<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Forum\Application\Conversations\ConversationReportService;
use Modules\Forum\Domain\Entities\ConversationMessage;
use Modules\Forum\Domain\Entities\ConversationReport;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;

/**
 * Reporting a private message from its conversation, and settling the report from the forum's
 * moderation page (JSON).
 */
final class ConversationReportController extends Controller
{
    use RespondsWithConversationErrors;

    public function __construct(
        private readonly ConversationReportService $reports,
    ) {}

    public function store(Request $request, string $message): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate([
            'reason' => ['required', 'string', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:'.ConversationReport::MAX_NOTE_LENGTH],
        ]);

        return $this->respond(function () use ($user, $message, $input): JsonResponse {
            $this->reports->report(
                $user,
                ConversationMessageId::fromString(strtolower($message)),
                ReportReason::from((string) $input['reason']),
                is_string($input['note'] ?? null) ? $input['note'] : null,
            );

            return response()->json(['reported' => true], 201);
        });
    }

    public function resolve(Request $request, string $report): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate([
            'resolution' => ['required', 'string', Rule::in([ReportResolution::Hidden->value, ReportResolution::NoAction->value])],
            'reason' => ['nullable', 'string', 'max:'.ConversationMessage::MAX_REASON_LENGTH],
        ]);

        return $this->respond(function () use ($user, $report, $input): JsonResponse {
            $settled = $this->reports->resolve(
                $user,
                ConversationReportId::fromString(strtolower($report)),
                ReportResolution::from((string) $input['resolution']),
                is_string($input['reason'] ?? null) ? $input['reason'] : null,
            );

            return response()->json(['settled' => $settled]);
        });
    }
}
