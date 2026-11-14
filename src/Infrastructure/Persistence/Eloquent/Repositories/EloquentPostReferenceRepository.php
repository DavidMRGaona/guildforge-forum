<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Domain\Enums\PostReferenceKind;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\PostReferenceRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\PostId;

final class EloquentPostReferenceRepository implements PostReferenceRepositoryInterface
{
    private const string TABLE = 'forum_post_references';

    public function replaceFor(PostId $source, array $referenced, array $quoted): void
    {
        $rows = [];
        foreach ([PostReferenceKind::Reference->value => $referenced, PostReferenceKind::Quote->value => $quoted] as $kind => $ids) {
            foreach ($this->ids($ids) as $target) {
                if ($target !== $source->value) {
                    $rows[$target.' '.$kind] = ['source_post_id' => $source->value, 'target_post_id' => $target, 'kind' => $kind];
                }
            }
        }

        DB::table(self::TABLE)->where('source_post_id', $source->value)->delete();
        if ($rows !== []) {
            DB::table(self::TABLE)->insert(array_values($rows));
        }
    }

    public function targetsOf(PostId $source): array
    {
        return array_values(array_unique(DB::table(self::TABLE)
            ->where('source_post_id', $source->value)
            ->pluck('target_post_id')
            ->map(static fn (mixed $id): string => strtolower((string) $id))
            ->all()));
    }

    public function countsFor(array $targets): array
    {
        $ids = $this->ids($targets);
        if ($ids === []) {
            return [];
        }

        $counts = [];
        $rows = $this->visibleSources()
            ->whereIn('edges.target_post_id', $ids)
            ->groupBy('edges.target_post_id', 'sources.discussion_id')
            ->selectRaw('edges.target_post_id as target, sources.discussion_id as discussion, count(distinct edges.source_post_id) as replies')
            ->get();
        foreach ($rows as $row) {
            $counts[strtolower((string) $row->target)][strtolower((string) $row->discussion)] = (int) $row->replies;
        }

        return $counts;
    }

    public function visibleSourcesOf(PostId $target): array
    {
        return array_values($this->visibleSources()
            ->where('edges.target_post_id', $target->value)
            ->distinct()
            ->orderBy('sources.published_at')
            ->orderBy('sources.id')
            ->get(['sources.id as post', 'sources.discussion_id as discussion', 'sources.published_at'])
            ->map(static fn (object $row): array => ['postId' => strtolower((string) $row->post), 'discussionId' => strtolower((string) $row->discussion)])
            ->all());
    }

    /**
     * The rows whose pointing post is visible, joined with that post as `sources`.
     */
    private function visibleSources(): Builder
    {
        return DB::table(self::TABLE.' as edges')
            ->join('forum_posts as sources', 'sources.id', '=', 'edges.source_post_id')
            ->where('sources.status', PostStatus::Visible->value);
    }

    /**
     * Well-formed ids, lowercase and each once (a malformed uuid would make PostgreSQL throw).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(AccountId::normalize(...), $ids), is_string(...))));
    }
}
