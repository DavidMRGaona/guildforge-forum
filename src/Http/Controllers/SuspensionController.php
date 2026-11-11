<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\ModerationLogEntry;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\ValueObjects\SuspensionId;

/**
 * Suspending someone from a post's moderation menu, and lifting it. JSON, for whoever
 * moderates the whole forum.
 */
final class SuspensionController extends Controller
{
    use RespondsWithForumErrors;

    /** The longest suspension with an end, in days; longer ones have no end */
    public const int MAX_DAYS = 365;

    public function __construct(
        private readonly SuspensionService $suspensions,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'user' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:'.Suspension::MAX_REASON_LENGTH],
            // Null: until someone lifts it
            'days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
        ]);

        return $this->refusals(function () use ($request, $input): JsonResponse {
            $days = $input['days'] ?? null;
            $suspension = $this->suspensions->suspend(
                $this->user($request),
                (string) $input['user'],
                (string) $input['reason'],
                is_numeric($days) ? CarbonImmutable::now()->addDays((int) $days) : null,
            );

            return response()->json(['id' => $suspension->id()->value], 201);
        });
    }

    public function destroy(Request $request, string $suspension): JsonResponse
    {
        $input = $request->validate([
            'reason' => ['nullable', 'string', 'max:'.ModerationLogEntry::MAX_REASON_LENGTH],
        ]);

        return $this->refusals(function () use ($request, $suspension, $input): JsonResponse {
            $reason = is_string($input['reason'] ?? null) && trim($input['reason']) !== '' ? $input['reason'] : null;
            $this->suspensions->lift($this->user($request), SuspensionId::fromString($suspension), $reason);

            return response()->json(['lifted' => true]);
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
                return response()->json(['message' => __('forum::messages.moderation.errors.invalid_record')], 409);
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
