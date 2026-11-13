<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Exceptions\RollRevealException;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;

/**
 * Reveals a hidden roll to everyone, for good. Who may is HiddenRolls' to say; who did and when
 * stays with the roll. The post's plain text is written again with the result, so the search,
 * mail and quotations pick it up; the post is not edited (no version, no edit mark).
 */
final readonly class RollReveals
{
    public function __construct(
        private DiceRollRepositoryInterface $rolls,
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
        private HiddenRolls $hiddenRolls,
        private PostRenderer $renderer,
        private ForumLiveSignalsInterface $live,
    ) {}

    /**
     * @return Post the roll's post, with its new plain text
     *
     * @throws PostNotFoundException when there is no such roll or the person may not read its post
     * @throws RollRevealException when there is nothing to reveal
     * @throws ForumActionDeniedException when the person may not reveal it
     */
    public function reveal(Authenticatable $actor, string $rollId): Post
    {
        $roll = $this->rolls->findMany([$rollId])[0] ?? null;
        $post = $roll instanceof DiceRoll ? $this->posts->find($roll->postId) : null;
        $discussion = $post instanceof Post ? $this->discussions->find($post->discussionId()) : null;
        if (! $roll instanceof DiceRoll || ! $post instanceof Post || ! $discussion instanceof Discussion || ! $this->access->canRead($actor, $discussion)) {
            throw PostNotFoundException::withId($rollId);
        }

        $accountId = $actor->getAuthIdentifier();
        if (! is_string($accountId)) {
            throw ForumActionDeniedException::because('reveal a roll');
        }

        // What the roll allows first (never hidden, revealed already, taken out), then who may
        $revealed = $roll->revealedBy(strtolower($accountId), CarbonImmutable::now()->toDateTimeImmutable());
        if ($post->status() === PostStatus::Deleted) {
            throw RollRevealException::removed($rollId);
        }
        if (! $this->hiddenRolls->mayReveal($actor, $roll)) {
            throw ForumActionDeniedException::because('reveal a roll');
        }

        return DB::transaction(function () use ($revealed, $post): Post {
            if (! $this->rolls->markRevealed($revealed->id, (string) $revealed->revealedBy, $revealed->revealedAt ?? CarbonImmutable::now()->toDateTimeImmutable())) {
                throw RollRevealException::alreadyRevealed($revealed->id);
            }

            $current = array_values(array_filter($this->rolls->ofPost($post->id()), static fn (DiceRoll $roll): bool => ! $roll->isRemoved()));
            $this->posts->replacePlainText($post->id(), $this->renderer->plainText($post->body()->document, $current));
            // Open pages ask for the post again, each drawn by the hidden rolls' rules for its reader
            $this->live->postChanged($post);

            return $this->posts->findOrFail($post->id());
        });
    }
}
