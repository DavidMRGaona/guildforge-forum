<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use App\Application\Services\SettingsServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\Queries\ActivitySubjectRowDTO;
use Modules\Forum\Application\Queries\ActivitySubjectsQueryInterface;
use Modules\Forum\Infrastructure\Services\EloquentSystemAccount;

/**
 * The posts and discussions that count as activity, in id order (keyset pages, so a new row never
 * shifts a walk). The system account is known by the id the settings keep, never created here.
 */
final readonly class EloquentActivitySubjectsQuery implements ActivitySubjectsQueryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
        private SettingsServiceInterface $settings,
    ) {}

    public function posts(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array
    {
        if ($categoryIds === [] || $onlyIds === [] || $limit < 1) {
            return [];
        }

        $query = EveryMemberReads::postsInListedDiscussions(DB::table('forum_posts as p'), 'p', $categoryIds)
            // A shared character names itself, never its writer: whoever wrote it stays unknown
            ->leftJoin('forum_characters as c', 'c.id', '=', 'p.character_id')
            ->where(static function (Builder $where): void {
                $where->whereNull('c.id')->orWhereNotNull('c.owner_id');
            });

        // As a personal character (or one deleted since), the forum names the writer only by their public name
        $query->leftJoin('users as u', 'u.id', '=', 'p.author_id')
            ->where(fn (Builder $where) => $this->outOfCharacterOrNamed($where, 'p'));

        return $this->rows($this->written($query, 'p.author_id'), 'p', $onlyIds, $afterId, $limit, 'p.published_at');
    }

    public function discussions(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array
    {
        if ($categoryIds === [] || $onlyIds === [] || $limit < 1) {
            return [];
        }

        // A discussion with no visible post (its first awaits approval, or every one was hidden) is not listed
        $query = EveryMemberReads::discussions(DB::table('forum_discussions as d'), 'd', $categoryIds)->where('d.post_count', '>', 0);

        // Opened as a personal character by someone without a public name, the forum never names the account
        $query->leftJoin('forum_posts as fp', static function (JoinClause $join): void {
            $join->on('fp.discussion_id', '=', 'd.id')->where('fp.number', '=', 1);
        })
            ->leftJoin('users as u', 'u.id', '=', 'd.author_id')
            ->where(function (Builder $where): void {
                // After a split or a merge the first post may be someone else's: its character says nothing then
                $where->whereNull('fp.id')
                    ->orWhereNull('fp.author_id')
                    ->orWhereColumn('fp.author_id', '!=', 'd.author_id')
                    ->orWhere(fn (Builder $named) => $this->outOfCharacterOrNamed($named, 'fp'));
            });

        return $this->rows($this->written($query, 'd.author_id'), 'd', $onlyIds, $afterId, $limit, 'd.created_at');
    }

    /**
     * The post (`p` or `fp`) speaks out of character, or its writer (`u`) has a public name (the
     * rule of CharacterWriterNames and of the forum's filter by author).
     */
    private function outOfCharacterOrNamed(Builder $where, string $post): void
    {
        $where->where(static function (Builder $outOfCharacter) use ($post): void {
            $outOfCharacter->whereNull("{$post}.character_id")->where("{$post}.character_removed", false);
        })->orWhere(static function (Builder $named): void {
            // PHP's trim() (AccountDirectory, PublicProfileDTO::hasPublicName()) also drops tabs,
            // line breaks and vertical tabs, which SQL's trim() keeps
            $named->whereNotNull('u.display_name')
                ->whereRaw("trim(replace(replace(replace(replace(u.display_name, ?, ''), ?, ''), ?, ''), ?, '')) <> ''", ["\t", "\n", "\r", "\x0B"]);
        });
    }

    private function written(Builder $query, string $author): Builder
    {
        $query->whereNotNull($author);

        $system = $this->settings->get(EloquentSystemAccount::SETTING);
        if (is_string($system) && Str::isUuid($system)) {
            $query->where($author, '!=', strtolower($system));
        }

        return $query;
    }

    /**
     * @param  list<string>|null  $onlyIds
     * @return list<ActivitySubjectRowDTO>
     */
    private function rows(Builder $query, string $table, ?array $onlyIds, ?string $afterId, int $limit, string $occurredAt): array
    {
        if ($onlyIds !== null) {
            $query->whereIn("{$table}.id", $onlyIds);
        }

        if ($afterId !== null) {
            $query->where("{$table}.id", '>', $afterId);
        }

        $rows = $query->orderBy("{$table}.id")
            ->limit($limit)
            ->get(["{$table}.id as id", "{$table}.author_id as author_id", "{$occurredAt} as occurred_at"]);

        $subjects = [];
        foreach ($rows as $row) {
            $subjects[] = new ActivitySubjectRowDTO(
                id: strtolower((string) $row->id),
                authorId: strtolower((string) $row->author_id),
                occurredAt: CarbonImmutable::parse((string) $row->occurred_at, $this->timezone->storageTimezone())->toDateTimeImmutable(),
            );
        }

        return $subjects;
    }
}
