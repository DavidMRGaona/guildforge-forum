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

        // Visible posts among the ones asked for and every opening message (a discussion hit quotes it)
        $replyNumbers = array_values(array_unique(array_filter(array_map(static fn (array $address): int => $address[1], $addresses))));
        $posts = [];
        if ($discussions->isNotEmpty()) {
            foreach (PostModel::query()
                ->whereIn('discussion_id', $discussions->pluck('id')->all())
                ->whereIn('number', [1, ...$replyNumbers])
                ->where('status', PostStatus::Visible->value)
                ->get(['discussion_id', 'number']) as $post) {
                $posts[strtolower($post->discussion_id).':'.$post->number] = true;
            }
        }

        $visible = [];
        foreach ($addresses as [$number, $reply]) {
            $discussion = $discussions[$number] ?? null;
            if ($discussion === null) {
                continue;
            }

            $id = strtolower($discussion->id);
            if ($reply > 0 && ! isset($posts["{$id}:{$reply}"])) {
                continue;
            }

            $visible["{$number}:{$reply}"] = new VisibleSearchHitDTO(strtolower($discussion->category_id), $discussion->title, isset($posts["{$id}:1"]));
        }

        return $visible;
    }
}
