<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Queries\ProfileActivityCriteria;
use Modules\Forum\Application\Queries\ProfileActivityQueryInterface;
use Modules\Forum\Application\Queries\ProfileCharacterUseDTO;
use Modules\Forum\Application\Queries\ProfilePostRowDTO;
use Modules\Forum\Domain\Enums\PostStatus;

/**
 * A person's activity for their profile: their visible messages (index author, status, published)
 * in visible, numbered topics of the categories given, and the topics they opened (index author).
 */
final readonly class EloquentProfileActivityQuery implements ProfileActivityQueryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function charactersUsedBy(string $authorId, array $categoryIds): ProfileCharacterUseDTO
    {
        if ($categoryIds === []) {
            return new ProfileCharacterUseDTO([], false);
        }

        // The messages counted and listed, and the first message of each topic the person opened, whatever
        // its state: the topics count by it, so its character is decided with the same rules
        $query = DB::table('forum_posts as p')
            ->join('forum_discussions as d', 'd.id', '=', 'p.discussion_id')
            ->where('p.author_id', $authorId)
            ->whereNotNull('p.number')
            ->where(static function (Builder $counted) use ($authorId): void {
                $counted->where(static function (Builder $visible): void {
                    $visible->where('p.status', PostStatus::Visible->value)->whereNotNull('p.published_at');
                })->orWhere(static function (Builder $opening) use ($authorId): void {
                    $opening->where('p.number', 1)->where('d.author_id', $authorId);
                });
            });

        $rows = $this->visibleTopics($query, $categoryIds)
            ->where(static function (Builder $where): void {
                $where->whereNotNull('p.character_id')->orWhere('p.character_removed', true);
            })
            ->distinct()
            ->get(['p.character_id', 'p.character_removed']);

        $ids = [];
        foreach ($rows as $row) {
            if ($row->character_id !== null) {
                $ids[strtolower((string) $row->character_id)] = true;
            }
        }

        return new ProfileCharacterUseDTO(array_keys($ids), $rows->isNotEmpty());
    }

    public function latestPosts(ProfileActivityCriteria $criteria, int $limit): array
    {
        if ($criteria->categoryIds === [] || $limit < 1) {
            return [];
        }

        $rows = $this->posts($criteria)
            ->orderByDesc('p.published_at')
            ->orderByDesc('p.id')
            ->limit($limit)
            ->get(['p.number', 'p.body', 'p.published_at', 'd.number as discussion_number', 'd.title', 'd.category_id']);

        $posts = [];
        foreach ($rows as $row) {
            $document = is_string($row->body) ? json_decode($row->body, true) : null;
            $posts[] = new ProfilePostRowDTO(
                number: (int) $row->number,
                discussionNumber: (int) $row->discussion_number,
                discussionTitle: (string) $row->title,
                categoryId: strtolower((string) $row->category_id),
                publishedAt: CarbonImmutable::parse((string) $row->published_at, $this->timezone->storageTimezone())->toDateTimeImmutable(),
                document: is_array($document) ? $document : [],
            );
        }

        return $posts;
    }

    public function countPosts(ProfileActivityCriteria $criteria): int
    {
        return $criteria->categoryIds === [] ? 0 : $this->posts($criteria)->count();
    }

    public function countTopics(ProfileActivityCriteria $criteria): int
    {
        if ($criteria->categoryIds === []) {
            return 0;
        }

        $query = $this->visibleTopics(DB::table('forum_discussions as d'), $criteria->categoryIds)
            ->where('d.author_id', $criteria->authorId)
            // A discussion with no visible post (its first awaits approval) is not listed yet
            ->where('d.post_count', '>', 0)
            // The first post, for the character its author may have opened it as
            ->leftJoin('forum_posts as fp', static function (JoinClause $join): void {
                $join->on('fp.discussion_id', '=', 'd.id')->where('fp.number', '=', 1);
            });

        if ($this->writerRulesApply($criteria)) {
            // After a split or a merge the first post may be someone else's: its character says nothing then
            $query->where(function (Builder $where) use ($criteria): void {
                $where->whereNull('fp.author_id')
                    ->orWhere('fp.author_id', '!=', $criteria->authorId)
                    ->orWhere(fn (Builder $shown) => $this->writerShown($shown, $criteria, 'fp'));
            });
        }

        return $query->count();
    }

    private function posts(ProfileActivityCriteria $criteria): Builder
    {
        $query = $this->visiblePosts($criteria->authorId, $criteria->categoryIds);
        if ($this->writerRulesApply($criteria)) {
            $query->where(fn (Builder $shown) => $this->writerShown($shown, $criteria, 'p'));
        }

        return $query;
    }

    /**
     * @param  list<string>  $categoryIds
     */
    private function visiblePosts(string $authorId, array $categoryIds): Builder
    {
        $query = DB::table('forum_posts as p')
            ->join('forum_discussions as d', 'd.id', '=', 'p.discussion_id')
            ->where('p.author_id', $authorId)
            ->where('p.status', PostStatus::Visible->value)
            ->whereNotNull('p.number')
            ->whereNotNull('p.published_at');

        return $this->visibleTopics($query, $categoryIds);
    }

    /**
     * @param  list<string>  $categoryIds
     */
    private function visibleTopics(Builder $query, array $categoryIds): Builder
    {
        return $query->whereIn('d.category_id', $categoryIds)
            ->where('d.is_hidden', false)
            ->where('d.awaiting_approval', false)
            ->whereNotNull('d.number');
    }

    private function writerRulesApply(ProfileActivityCriteria $criteria): bool
    {
        return $criteria->hiddenCharacterIds !== [] || $criteria->hidesCharacterPosts;
    }

    /**
     * Whether the post (`p` or `fp`) may be shown under its writer: always where the reader
     * moderates the category, elsewhere when it speaks neither as a shared character that hides
     * its writer nor, when the criteria say so, as any character at all.
     */
    private function writerShown(Builder $where, ProfileActivityCriteria $criteria, string $post): void
    {
        $where->where(static function (Builder $shown) use ($criteria, $post): void {
            if ($criteria->hidesCharacterPosts) {
                $shown->whereNull("{$post}.character_id")->where("{$post}.character_removed", false);
            } elseif ($criteria->hiddenCharacterIds !== []) {
                $shown->where(static function (Builder $character) use ($criteria, $post): void {
                    $character->whereNull("{$post}.character_id")->orWhereNotIn("{$post}.character_id", $criteria->hiddenCharacterIds);
                });
            }
        });

        if ($criteria->moderatedCategoryIds !== []) {
            $where->orWhereIn('d.category_id', $criteria->moderatedCategoryIds);
        }
    }
}
