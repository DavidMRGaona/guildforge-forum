<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Forum\Application\Services\ReportService;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Entities\Report;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Reporting a post and, for its moderators, settling its reports. JSON.
 */
final class ReportController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly ReportService $reports,
    ) {}

    public function store(Request $request, string $post): JsonResponse
    {
        $input = $request->validate([
            'reason' => ['required', 'string', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:'.Report::MAX_NOTE_LENGTH],
        ]);

        return $this->refusals(function () use ($request, $post, $input): JsonResponse {
            $this->reports->report(
                $this->user($request),
                PostId::fromString($post),
                ReportReason::from((string) $input['reason']),
                is_string($input['note'] ?? null) ? $input['note'] : null,
            );

            return response()->json(['reported' => true], 201);
        });
    }

    public function resolve(Request $request, string $post): JsonResponse
    {
        $input = $request->validate([
            'resolution' => ['required', 'string', Rule::enum(ReportResolution::class)],
            'reason' => ['nullable', 'string', 'max:'.ModerationLogEntry::MAX_REASON_LENGTH],
        ]);

        return $this->refusals(function () use ($request, $post, $input): JsonResponse {
            $settled = $this->reports->resolve(
                $this->user($request),
                PostId::fromString($post),
                ReportResolution::from((string) $input['resolution']),
                is_string($input['reason'] ?? null) && trim($input['reason']) !== '' ? $input['reason'] : null,
            );

            return response()->json(['settled' => $settled]);
        });
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function refusals(Closure $action): JsonResponse
    {
        return $this->respond(function () use ($action): JsonResponse {
            try {
                return $action();
            } catch (ModerationRefusedException $exception) {
                $message = __("forum::messages.moderation.errors.{$exception->reason}");

                return response()->json(['message' => $message, 'reason' => $exception->reason], 422);
            } catch (InvalidModerationRecordException) {
                return response()->json(['message' => __('forum::messages.moderation.errors.invalid_record')], 422);
            }
        });
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
