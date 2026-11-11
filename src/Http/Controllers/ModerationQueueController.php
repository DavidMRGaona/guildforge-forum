<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Queries\PendingPostDTO;
use Modules\Forum\Application\Queries\ReportedPostDTO;
use Modules\Forum\Application\Services\ModerationQueue;
use Modules\Forum\Domain\Enums\ReportReason;

/**
 * «Moderación» in the forum (/foro/moderacion): reported posts and posts awaiting approval in
 * the categories the person moderates, to settle without the panel. Whoever moderates nothing
 * gets 404.
 */
final class ModerationQueueController extends Controller
{
    public function __construct(
        private readonly ModerationQueue $queue,
        private readonly AccountDirectoryInterface $accounts,
        private readonly ForumUrlsInterface $urls,
        private readonly TimezoneServiceInterface $timezone,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null && $this->queue->hasQueue($user), 404);

        $reported = $this->queue->reportedPosts($user);
        $pending = $this->queue->pendingPosts($user);
        $names = $this->accounts->namesOf(array_values(array_unique(array_filter([
            ...array_map(static fn (ReportedPostDTO $row): ?string => $row->authorId, $reported),
            ...array_map(static fn (PendingPostDTO $row): ?string => $row->authorId, $pending),
        ]))));

        return Inertia::render('Forum/Moderation', [
            'reported' => array_map(fn (ReportedPostDTO $row): array => [
                'postId' => $row->postId,
                'status' => $row->status,
                'excerpt' => $row->excerpt,
                'authorName' => $row->authorId === null ? null : ($names[$row->authorId] ?? null),
                'discussionTitle' => $row->discussionTitle,
                'url' => $this->urls->discussionFrom($row->discussionNumber, $row->discussionTitle, $row->postNumber),
                'reports' => $row->reports,
                'reasons' => array_map(static fn (string $reason): string => __('forum::messages.reports.reasons.'.(ReportReason::tryFrom($reason) ?? ReportReason::Other)->value), $row->reasons),
                'notes' => $row->notes,
                'firstReportedLabel' => $this->timezone->format($row->firstReportedAt),
                'resolveUrl' => route('forum.reports.resolve', ['post' => $row->postId]),
            ], $reported),
            'pending' => array_map(fn (PendingPostDTO $row): array => [
                'postId' => $row->postId,
                'excerpt' => $row->excerpt,
                'authorName' => $row->authorId === null ? null : ($names[$row->authorId] ?? null),
                'discussionTitle' => $row->discussionTitle,
                'url' => $this->urls->discussionFrom($row->discussionNumber, $row->discussionTitle),
                'opensDiscussion' => $row->opensDiscussion,
                'writtenLabel' => $this->timezone->format($row->writtenAt),
                'moderationUrl' => route('forum.moderation.posts', ['post' => $row->postId]),
            ], $pending),
            'seo' => $this->seo->forPage(new SeoPageDTO(title: __('forum::messages.moderation.queue_title'), noindex: true))->toArray(),
        ]);
    }
}
