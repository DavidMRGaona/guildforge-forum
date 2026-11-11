<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\SubjectConversationDTO;
use Modules\Forum\Application\Services\SubjectConversationReader;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Http\Presenters\EditorProps;
use Modules\Forum\Http\Presenters\EngagementPresenter;
use Modules\Forum\Http\Presenters\ForumPresenter;
use Modules\Forum\Http\Presenters\ModerationProps;

/**
 * The conversation of a record, for the `subject-discussion` slot of its page
 * (/foro/fichas/<type>/<id>[?antes=<number>]). The page asks for it once it is drawn, so it never
 * waits for the forum. Whatever cannot be shown answers `{"conversation": null}`, never an error.
 */
final class SubjectConversationController extends Controller
{
    public function __construct(
        private readonly SubjectConversationReader $reader,
        private readonly ForumPresenter $presenter,
        private readonly EditorProps $editor,
        private readonly ForumUrlsInterface $urls,
        private readonly EngagementPresenter $engagement,
        private readonly ModerationProps $moderationProps,
    ) {}

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        $user = $request->user();
        $before = $request->query('antes');
        $before = is_string($before) && preg_match('/^[1-9][0-9]{0,9}$/', $before) === 1 ? (int) $before : null;

        $conversation = $this->reader->read($user, $type, $id, $before);

        // What it holds depends on who asks: no shared cache may keep it
        return response()
            ->json(['conversation' => $conversation instanceof SubjectConversationDTO ? $this->present($conversation, $user) : null])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SubjectConversationDTO $conversation, ?Authenticatable $user): array
    {
        $discussion = $conversation->discussion;
        $discussionId = $discussion->id()->value;
        $olderBefore = $conversation->olderBefore();
        $signedIn = $user instanceof Authenticatable;
        ['posts' => $posts, 'mentionNames' => $mentionNames] = $this->presenter->posts($discussion, $conversation->posts, $conversation->contents);

        return [
            'discussion' => [
                'id' => $discussionId,
                'number' => $discussion->number(),
                'title' => $discussion->title()->value,
                'url' => $this->urls->discussion($discussion),
                'isClosed' => $discussion->status() === DiscussionStatus::Closed,
                'closedReason' => $conversation->closedReason?->value,
            ],
            'posts' => $this->moderationProps->withReportLinks($this->engagement->withLikes($posts, $conversation->posts, $discussion, $user), $user),
            'mentionNames' => $mentionNames,
            'olderCount' => $conversation->olderCount,
            'olderNext' => min(SubjectConversationReader::EARLIER, $conversation->olderCount),
            'olderUrl' => $olderBefore === null ? null : route('forum.subjects.show', [
                'type' => $conversation->key->type,
                'id' => $conversation->key->id,
                'antes' => $olderBefore,
            ], absolute: false),
            'highestNumber' => $conversation->highestNumber,
            'lastReadNumber' => $conversation->lastReadNumber,
            'readUrl' => $signedIn ? route('forum.discussions.read', ['discussion' => $discussionId]) : null,
            'reply' => [
                'canPost' => $conversation->canPost,
                'postUrl' => route('forum.posts.store', ['discussion' => $discussionId]),
                'mentionsUrl' => $signedIn ? route('forum.mentions.index', ['discussion' => $discussionId]) : null,
                ...$this->editor->for($user),
                'suspension' => $this->moderationProps->suspensionNotice($user),
            ],
        ];
    }
}
