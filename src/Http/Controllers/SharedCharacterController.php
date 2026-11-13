<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CharacterRules;
use Modules\Forum\Application\Services\CharacterService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Http\Presenters\CharacterPresenter;
use Modules\Forum\Http\Requests\SaveCharacterRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The shared characters (PNJ) of a campaign's space, all JSON: whoever directs the campaign (and
 * whoever moderates the space) lists them, and its directors create them. Changing, retiring and
 * deleting go through the characters' own routes. A space the person may not read is not found
 * (404); one they read without directing the campaign answers 403.
 */
final class SharedCharacterController extends Controller
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CharacterPresenter $presenter,
        private readonly CategoryRepositoryInterface $categories,
        private readonly CategoryAccessPolicy $policy,
    ) {}

    public function index(Request $request, string $category): JsonResponse
    {
        $user = $this->user($request);
        $space = $this->space($user, $category);

        try {
            return response()->json($this->presenter->shared($user, $space));
        } catch (ForumActionDeniedException) {
            return $this->forbidden();
        }
    }

    public function store(SaveCharacterRequest $request, string $category): JsonResponse
    {
        $user = $this->user($request);
        $space = $this->space($user, $category);

        try {
            $character = $this->characters->createShared($user, $space, $request->characterInput());

            return response()->json(['character' => $this->presenter->sharedItem($user, $character)], 201);
        } catch (ForumActionDeniedException) {
            return $this->forbidden();
        } catch (InvalidCharacterException $exception) {
            $message = __('forum::messages.characters.errors.'.($exception->reason === InvalidCharacterException::TOO_MANY ? 'too_many_shared' : $exception->reason), [
                'name' => Character::MAX_NAME_LENGTH,
                'description' => Character::MAX_DESCRIPTION_LENGTH,
                'max' => CharacterRules::MAX_SHARED_PER_SPACE,
            ]);

            return response()->json(['message' => $message, 'reason' => $exception->reason, 'errors' => [$exception->field => [$message]]], 422);
        }
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => __('forum::messages.characters.errors.shared_forbidden')], 403);
    }

    private function space(Authenticatable $user, string $slug): Category
    {
        $space = $this->categories->findBySlug(new CategorySlug($slug));
        if (! $space instanceof Category || ! $space->isPublicationSpace() || ! $this->policy->canView($user, $space)) {
            throw new NotFoundHttpException;
        }

        return $space;
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();
        abort_unless($user instanceof Authenticatable, 401);

        return $user;
    }
}
