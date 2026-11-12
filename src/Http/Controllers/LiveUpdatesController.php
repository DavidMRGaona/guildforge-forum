<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Forum\Application\DTOs\NewPostsDTO;
use Modules\Forum\Application\Services\ForumPulse;
use Modules\Forum\Application\Services\NewPostsReader;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Http\Presenters\EngagementPresenter;
use Modules\Forum\Http\Presenters\ForumPresenter;
use Modules\Forum\Http\Presenters\MergedDiscussionAnswer;
use Modules\Forum\Http\Presenters\ModerationProps;

/**
 * What an open page asks for without reloading, as light JSON that no shared cache keeps:
 * - the posts of a discussion after the last one the page has (204 when there are none);
 * - the forum's summary for the menu and the lists.
 * A discussion the person may not read answers like one that does not exist; one merged into
 * another answers 410 with where it went, when the person may read that.
 */
final class LiveUpdatesController extends Controller
{
    private const string NO_STORE = 'private, no-store';

    public function __construct(
        private readonly NewPostsReader $reader,
        private readonly ForumPulse $pulse,
        private readonly ForumPresenter $presenter,
        private readonly EngagementPresenter $engagement,
        private readonly ModerationProps $moderationProps,
        private readonly MergedDiscussionAnswer $merged,
    ) {}

    public function posts(Request $request, string $discussion): JsonResponse|Response
    {
        $validated = $request->validate(['despues' => ['required', 'integer', 'min:0', 'max:4294967295']]);
        $user = $request->user();

        try {
            $news = $this->reader->after($user, DiscussionId::fromString($discussion), (int) $validated['despues']);
        } catch (DiscussionNotFoundException) {
            // A discussion merged into another says where it went, to whoever may read that
            $gone = $this->merged->for($user, $discussion);
            if ($gone instanceof JsonResponse) {
                return $gone;
            }

            // One answer for a discussion that does not exist and one that may not be read
            return response()->json(['message' => 'Not Found'], 404)->header('Cache-Control', self::NO_STORE);
        }

        if (! $news instanceof NewPostsDTO) {
            return response()->noContent()->header('Cache-Control', self::NO_STORE);
        }

        ['posts' => $posts, 'mentionNames' => $mentionNames] = $this->presenter->posts($news->discussion, $news->posts, $news->contents, $news->canModerate);

        return response()
            ->json([
                'posts' => $this->moderationProps->withReportLinks($this->engagement->withLikes($posts, $news->posts, $news->discussion, $user), $user),
                'mentionNames' => $mentionNames,
                'highestNumber' => $news->highestNumber,
                'more' => $news->more,
            ])
            ->header('Cache-Control', self::NO_STORE);
    }

    public function forum(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $ids = [];
        $asked = $request->query('temas');
        if ($asked !== null) {
            $ids = is_string($asked) ? explode(',', $asked) : [];
            abort_if(
                $ids === [] || count($ids) > ForumPulse::MAX_DISCUSSIONS || array_filter($ids, static fn (string $id): bool => ! Str::isUuid($id)) !== [],
                422,
            );
            $ids = array_values(array_unique(array_map(strtolower(...), $ids)));
        }

        $summary = $this->pulse->summary($user, $ids);

        return response()
            ->json(['unreadFollowed' => $summary['unreadFollowed'], 'discussions' => (object) $summary['discussions']])
            ->header('Cache-Control', self::NO_STORE);
    }
}
