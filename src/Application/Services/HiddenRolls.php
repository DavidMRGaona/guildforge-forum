<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Content\Rendering\RollView;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\PostId;
use Throwable;

/**
 * The only place that decides who sees the result of a hidden roll and who may reveal it. It
 * fails closed: a post, discussion or category that cannot be found, or any rule that throws
 * (reported), keeps the result from the reader and lets nobody reveal.
 *
 * - Whoever threw it sees it, wherever it is.
 * - Whoever moderates the post's discussion sees it (ForumAccessInterface::canModerate): the
 *   forum's moderators and the category's own.
 * - In a campaign's space, its direction sees it (CategoryAccessPolicy::leadsSpace), through the
 *   campaign source's roster; without that source (or its roster) a space has no direction.
 * - Whoever threw it (while they may act in the forum) and the campaign's direction may reveal
 *   it, once and for good, as long as they read the discussion. Moderation only sees it.
 *
 * A revealed roll, or one never hidden, is seen by everyone who reads the post.
 */
final class HiddenRolls
{
    /** @var array<string, array{Post, Discussion, Category|null}|null> by post id */
    private array $places = [];

    public function __construct(
        private readonly PostRepositoryInterface $posts,
        private readonly DiscussionRepositoryInterface $discussions,
        private readonly CategoryRepositoryInterface $categories,
        private readonly ForumAccessInterface $access,
        private readonly CategoryAccessPolicy $policy,
        private readonly ForumSuspensions $suspensions,
        private readonly AccountDirectoryInterface $accounts,
        private readonly TimezoneServiceInterface $timezone,
    ) {}

    /**
     * How each roll shows to the reader, by roll id.
     *
     * @param  list<DiceRoll>  $rolls
     * @param  bool  $offerReveal  whether the page lets the reader reveal from here
     * @return array<string, RollView>
     */
    public function viewsFor(array $rolls, ?Authenticatable $reader, bool $offerReveal = true): array
    {
        $views = [];
        foreach ($rolls as $roll) {
            if (! $roll->hidden) {
                $views[$roll->id] = RollView::of($roll);

                continue;
            }

            $views[$roll->id] = $this->maySee($reader, $roll)
                ? RollView::seenBy($roll, $offerReveal && $this->mayReveal($reader, $roll), $this->inCampaignSpace($roll))
                : RollView::of($roll);
        }

        return $this->withRevealers($views, $rolls);
    }

    /**
     * Whether the reader sees the roll's result.
     */
    public function maySee(?Authenticatable $reader, DiceRoll $roll): bool
    {
        if (! $roll->isSecret()) {
            return true;
        }

        return $this->decide(function () use ($reader, $roll): bool {
            if (! $reader instanceof Authenticatable) {
                return false;
            }

            if ($this->threw($reader, $roll)) {
                return true;
            }

            $place = $this->place($roll->postId);
            if ($place === null) {
                return false;
            }

            [, $discussion, $category] = $place;

            return $this->access->canModerate($reader, $discussion)
                || ($category instanceof Category && $this->policy->leadsSpace($reader, $category));
        });
    }

    /**
     * Whether the reader may reveal the roll now.
     */
    public function mayReveal(?Authenticatable $reader, DiceRoll $roll): bool
    {
        if (! $roll->isSecret() || $roll->isRemoved() || ! $reader instanceof Authenticatable) {
            return false;
        }

        return $this->decide(function () use ($reader, $roll): bool {
            $place = $this->place($roll->postId);
            if ($place === null) {
                return false;
            }

            [, $discussion, $category] = $place;
            if (! $this->access->canRead($reader, $discussion)) {
                return false;
            }

            return ($this->threw($reader, $roll) && ! $this->suspensions->isBarred($reader))
                || ($category instanceof Category && $this->policy->leadsSpace($reader, $category));
        });
    }

    private function threw(Authenticatable $reader, DiceRoll $roll): bool
    {
        $id = $reader->getAuthIdentifier();

        return $roll->rolledBy !== null && is_string($id) && strtolower($id) === strtolower($roll->rolledBy);
    }

    private function inCampaignSpace(DiceRoll $roll): bool
    {
        $category = $this->place($roll->postId)[2] ?? null;
        $subject = $category?->subject();

        return $subject instanceof CategorySubject && $subject->type === CampaignSpaceSync::TYPE;
    }

    /**
     * Who revealed each revealed roll, by the name their account has now (an anonymized one
     * shows the site's anonymous name), and when, in the display zone.
     *
     * @param  array<string, RollView>  $views
     * @param  list<DiceRoll>  $rolls
     * @return array<string, RollView>
     */
    private function withRevealers(array $views, array $rolls): array
    {
        $revealed = array_values(array_filter($rolls, static fn (DiceRoll $roll): bool => $roll->hidden && $roll->revealedAt instanceof DateTimeImmutable));
        if ($revealed === []) {
            return $views;
        }

        $names = $this->accounts->namesOf(array_values(array_unique(array_filter(array_map(static fn (DiceRoll $roll): ?string => $roll->revealedBy, $revealed)))));
        foreach ($revealed as $roll) {
            $views[$roll->id] = $views[$roll->id]->withRevealer(
                $roll->revealedBy === null ? null : ($names[$roll->revealedBy] ?? null),
                $roll->revealedAt instanceof DateTimeImmutable ? $this->timezone->format($roll->revealedAt) : null,
            );
        }

        return $views;
    }

    /**
     * The post, discussion and category a roll is in; null when the post or its discussion is
     * gone. Looked up once per post.
     *
     * @return array{Post, Discussion, Category|null}|null
     */
    private function place(PostId $postId): ?array
    {
        if (! array_key_exists($postId->value, $this->places)) {
            $post = $this->posts->find($postId);
            $discussion = $post instanceof Post ? $this->discussions->find($post->discussionId()) : null;

            $this->places[$postId->value] = $post instanceof Post && $discussion instanceof Discussion
                ? [$post, $discussion, $this->categories->find($discussion->categoryId())]
                : null;
        }

        return $this->places[$postId->value];
    }

    /**
     * @param  callable(): bool  $decision
     */
    private function decide(callable $decision): bool
    {
        try {
            return $decision();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
