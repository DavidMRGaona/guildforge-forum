<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\RedirectTargetDTO;
use Modules\Forum\Application\Services\ForumRedirects;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * What a page that was open when its discussion was merged into another is told when it keeps
 * asking (or writing) to the old id: 410 with the title and address of where the discussion went.
 * Only whoever may read the target is told; the rest get nothing here, so the caller answers
 * what it answers for a discussion that does not exist.
 */
final readonly class MergedDiscussionAnswer
{
    public function __construct(
        private ForumRedirects $redirects,
        private ForumUrlsInterface $urls,
    ) {}

    public function for(?Authenticatable $reader, string $discussionId): ?JsonResponse
    {
        $target = $this->redirects->mergedInto($reader, DiscussionId::fromString($discussionId));
        if (! $target instanceof RedirectTargetDTO) {
            return null;
        }

        $title = $target->discussion->title()->value;

        return response()
            ->json([
                'message' => __('forum::messages.errors.merged', ['title' => $title]),
                'mergedInto' => ['title' => $title, 'url' => $this->urls->discussion($target->discussion)],
            ], 410)
            ->header('Cache-Control', 'private, no-store');
    }
}
