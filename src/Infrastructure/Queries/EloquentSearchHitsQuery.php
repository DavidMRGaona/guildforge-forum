<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use Modules\Forum\Application\Queries\SearchHitsQueryInterface;
use Modules\Forum\Application\Queries\VisibleSearchHitDTO;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

final readonly class EloquentSearchHitsQuery implements SearchHitsQueryInterface
{
    public function visible(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $numbers = array_values(array_unique(array_map(static fn (array $address): int => $address[0], $addresses)));
        $discussions = DiscussionModel::query()
            ->whereIn('number', $numbers)
            ->where('is_hidden', false)
            ->where('awaiting_approval', false)
            ->get(['id', 'number', 'title', 'category_id'])
            ->keyBy('number');

        $replyNumbers = array_values(array_unique(array_filter(array_map(static fn (array $address): int => $address[1], $addresses))));
        $replies = [];
        if ($replyNumbers !== [] && $discussions->isNotEmpty()) {
            foreach (PostModel::query()
                ->whereIn('discussion_id', $discussions->pluck('id')->all())
                ->whereIn('number', $replyNumbers)
                ->where('status', PostStatus::Visible->value)
                ->get(['discussion_id', 'number']) as $post) {
                $replies[strtolower($post->discussion_id).':'.$post->number] = true;
            }
        }

        $visible = [];
        foreach ($addresses as [$number, $reply]) {
            $discussion = $discussions[$number] ?? null;
            if ($discussion === null || ($reply > 0 && ! isset($replies[strtolower($discussion->id).':'.$reply]))) {
                continue;
            }

            $visible["{$number}:{$reply}"] = new VisibleSearchHitDTO(strtolower($discussion->category_id), $discussion->title);
        }

        return $visible;
    }
}
