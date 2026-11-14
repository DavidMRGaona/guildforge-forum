<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Services\MemberTitleService;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;

/**
 * Whoever moderates the whole forum sets, changes or removes a person's title (a blank one removes it).
 */
final class MemberTitleController extends Controller
{
    use RespondsWithForumErrors;

    public function __construct(
        private readonly MemberTitleService $titles,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        $input = $request->validate([
            'user' => ['required', 'uuid'],
            // Checked again, trimmed, by MemberTitle: here only a bound on what is read
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->respond(function () use ($user, $input): JsonResponse {
            $title = is_string($input['title'] ?? null) ? $input['title'] : null;
            try {
                $held = $this->titles->change($user, (string) $input['user'], $title);
            } catch (ModerationRefusedException $exception) {
                $message = __("forum::messages.moderation.errors.{$exception->reason}");

                return response()->json(['message' => $message, 'reason' => $exception->reason], 422);
            } catch (InvalidModerationRecordException) {
                $message = __('forum::messages.moderation.errors.title_invalid');

                return response()->json(['message' => $message, 'errors' => ['title' => [$message]]], 422);
            }

            return response()->json(['title' => $held?->value]);
        });
    }
}
