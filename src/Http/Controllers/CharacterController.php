<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Services\CharacterRules;
use Modules\Forum\Application\Services\CharacterService;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Exceptions\CharacterNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Http\Presenters\CharacterPresenter;
use Modules\Forum\Http\Requests\SaveCharacterRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Characters, all JSON: the profile tab lists the person's own; their owner and their
 * moderators change, retire, bring back and delete them. A character the person may neither
 * own nor moderate is not found (404).
 */
final class CharacterController extends Controller
{
    /** Refusals about what the character is now (retired, with posts…), not about what was sent */
    private const array STATE_REASONS = [
        InvalidCharacterException::HAS_POSTS,
        InvalidCharacterException::LOCKED,
        InvalidCharacterException::NOT_RETIRED,
        InvalidCharacterException::ALREADY_RETIRED,
    ];

    public function __construct(
        private readonly CharacterService $characters,
        private readonly CharacterPresenter $presenter,
        private readonly CharacterRules $rules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->presenter->profile($this->user($request)));
    }

    public function store(SaveCharacterRequest $request): JsonResponse
    {
        return $this->respond(function () use ($request): JsonResponse {
            $user = $this->user($request);
            $character = $this->characters->create($user, $request->characterInput());

            return response()->json(['character' => $this->present($character, $user)], 201);
        });
    }

    public function update(SaveCharacterRequest $request, string $character): JsonResponse
    {
        return $this->respond(function () use ($request, $character): JsonResponse {
            $user = $this->user($request);

            return response()->json(['character' => $this->present($this->characters->update($user, $character, $request->characterInput()), $user)]);
        });
    }

    public function retire(Request $request, string $character): JsonResponse
    {
        return $this->respond(function () use ($request, $character): JsonResponse {
            $user = $this->user($request);

            return response()->json(['character' => $this->present($this->characters->retire($user, $character), $user)]);
        });
    }

    public function restore(Request $request, string $character): JsonResponse
    {
        return $this->respond(function () use ($request, $character): JsonResponse {
            $user = $this->user($request);

            return response()->json(['character' => $this->present($this->characters->restore($user, $character), $user)]);
        });
    }

    public function destroy(Request $request, string $character): JsonResponse
    {
        return $this->respond(function () use ($request, $character): JsonResponse {
            $this->characters->delete($this->user($request), $character);

            return response()->json(null, 204);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Character $character, Authenticatable $user): array
    {
        return $this->presenter->card($character, $this->rules->moderates($user, $character));
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function respond(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (CharacterNotFoundException $exception) {
            throw new NotFoundHttpException(message: $exception->getMessage(), previous: $exception);
        } catch (ForumActionDeniedException) {
            return response()->json(['message' => __('forum::messages.characters.errors.forbidden')], 403);
        } catch (InvalidCharacterException $exception) {
            $message = __("forum::messages.characters.errors.{$exception->reason}", [
                'name' => Character::MAX_NAME_LENGTH,
                'description' => Character::MAX_DESCRIPTION_LENGTH,
                'max' => CharacterService::MAX_PER_ACCOUNT,
            ]);

            return response()->json(
                ['message' => $message, 'reason' => $exception->reason, 'errors' => [$exception->field => [$message]]],
                in_array($exception->reason, self::STATE_REASONS, true) ? 409 : 422,
            );
        }
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
