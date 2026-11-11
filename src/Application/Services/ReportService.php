<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Entities\Report;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Events\ForumPostReported;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\ReportId;

/**
 * Reporting a post and settling reports. Anyone signed in who reads a post reports it, once
 * while their report is open, unless it is their own or they are suspended. Its moderators
 * settle every open report of the post at once: hiding it, editing it, or with no action.
 * Settling is logged (ForumModeration::settleReports); a report made is announced (ForumPostReported, after the commit) so the
 * notices can tell the moderators.
 */
final readonly class ReportService
{
    public function __construct(
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ReportRepositoryInterface $reports,
        private ForumAccessInterface $access,
        private ForumSuspensions $suspensions,
        private ForumModeration $moderation,
    ) {}

    /**
     * Whether the person may report the post: signed in, reading it shown, not theirs, and not
     * suspended.
     */
    public function canReport(?Authenticatable $user, Post $post, Discussion $discussion): bool
    {
        $userId = ForumBrowser::accountId($user);

        return $userId !== null
            && $post->status() === PostStatus::Visible
            && $post->authorId() !== $userId
            && $this->access->canRead($user, $discussion)
            && ! $this->suspensions->isSuspended($user);
    }

    /**
     * Reports the post; reporting it again while the first report is open changes nothing.
     *
     * @throws PostNotFoundException when the person may not read it
     * @throws ModerationRefusedException when they may not report it
     * @throws InvalidModerationRecordException for a note too long
     */
    public function report(Authenticatable $reporter, PostId $postId, ReportReason $reason, ?string $note): Report
    {
        [$post, $discussion] = $this->readable($reporter, $postId);
        if ($this->suspensions->isSuspended($reporter)) {
            throw ModerationRefusedException::suspended();
        }
        if (! $this->canReport($reporter, $post, $discussion)) {
            throw ModerationRefusedException::notReportable($postId->value);
        }

        $reporterId = (string) ForumBrowser::accountId($reporter);
        $open = $this->reports->openBy($postId, $reporterId);
        if ($open instanceof Report) {
            return $open;
        }

        $report = Report::file(ReportId::generate(), $postId, $reporterId, $reason, $note, CarbonImmutable::now());

        DB::transaction(function () use ($report, $discussion, $post): void {
            $this->reports->save($report);

            // After the commit, so a report that was rolled back is never announced
            DB::afterCommit(static fn () => event(new ForumPostReported(
                reportId: $report->id()->value,
                postId: $post->id()->value,
                discussionId: $discussion->id()->value,
                categoryId: $discussion->categoryId()->value,
                reason: $report->reason()->value,
            )));
        });

        return $report;
    }

    /**
     * Settles every open report of the post. Hiding hides the post too (with the reason, for
     * its author); editing and no action only close the reports (the edit is made apart).
     *
     * @return int how many reports it settled
     *
     * @throws PostNotFoundException
     * @throws ForumActionDeniedException unless the person moderates the post
     * @throws InvalidPostException when hiding a post that is not shown any more
     * @throws ModerationRefusedException for a reason too long
     */
    public function resolve(Authenticatable $moderator, PostId $postId, ReportResolution $resolution, ?string $reason = null): int
    {
        [$post, $discussion] = $this->readable($moderator, $postId);
        if (! $this->access->canModerate($moderator, $discussion)) {
            throw ForumActionDeniedException::because('settle reports');
        }

        return DB::transaction(function () use ($moderator, $post, $resolution, $reason): int {
            $open = count($this->reports->openOf($post->id()));

            // Hiding the post settles its reports as hidden
            if ($resolution === ReportResolution::Hidden && $post->status() === PostStatus::Visible) {
                $this->moderation->hidePost($moderator, $post->id(), $reason);

                return $open;
            }

            return $this->moderation->settleReports($moderator, $post->id(), $resolution, $reason);
        });
    }

    /**
     * @return array{Post, Discussion}
     *
     * @throws PostNotFoundException
     */
    private function readable(Authenticatable $user, PostId $postId): array
    {
        $post = $this->posts->find($postId);
        $discussion = $post instanceof Post ? $this->discussions->find($post->discussionId()) : null;

        if (! $post instanceof Post || ! $discussion instanceof Discussion || ! $this->access->canRead($user, $discussion)) {
            throw PostNotFoundException::withId($postId->value);
        }

        // A post the person may not see the content of is not theirs to report or settle
        if (! $post->isVisible() && ! $this->access->canModerate($user, $discussion)) {
            throw PostNotFoundException::withId($postId->value);
        }

        return [$post, $discussion];
    }
}
