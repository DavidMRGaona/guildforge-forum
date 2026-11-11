<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\ForumSuspensions;
use Modules\Forum\Domain\Entities\Suspension;

/**
 * What every page with posts adds for reporting and suspensions: where each post is reported,
 * and why and until when the reader may not write. Shared by the discussion page and the
 * conversation of a record's page.
 */
final readonly class ModerationProps
{
    public function __construct(
        private ForumSuspensions $suspensions,
        private TimezoneServiceInterface $timezone,
    ) {}

    /**
     * Adds `reportUrl` to each presented post: only for posts shown, by someone else, and for a
     * reader signed in and not suspended.
     *
     * @param  list<array<string, mixed>>  $posts
     * @return list<array<string, mixed>>
     */
    public function withReportLinks(array $posts, ?Authenticatable $reader): array
    {
        $readerId = ForumBrowser::accountId($reader);
        $reports = $readerId !== null && ! $this->suspensions->isSuspended($reader);

        return array_map(static fn (array $post): array => [
            ...$post,
            'reportUrl' => $reports && ($post['status'] ?? null) === 'visible' && ($post['authorId'] ?? null) !== $readerId && is_string($post['id'] ?? null)
                ? route('forum.reports.store', ['post' => $post['id']])
                : null,
        ], $posts);
    }

    /**
     * Why and until when the reader may not write, while they are suspended.
     *
     * @return array{reason: string, untilLabel: string|null}|null
     */
    public function suspensionNotice(?Authenticatable $reader): ?array
    {
        $readerId = ForumBrowser::accountId($reader);
        $suspension = $readerId === null ? null : $this->suspensions->activeFor($readerId);
        if (! $suspension instanceof Suspension) {
            return null;
        }

        $endsAt = $suspension->endsAt();

        return ['reason' => $suspension->reason(), 'untilLabel' => $endsAt instanceof DateTimeImmutable ? $this->timezone->format($endsAt) : null];
    }
}
