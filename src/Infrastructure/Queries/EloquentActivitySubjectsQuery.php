<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use App\Application\Services\SettingsServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\Queries\ActivitySubjectRowDTO;
use Modules\Forum\Application\Queries\ActivitySubjectsQueryInterface;
use Modules\Forum\Domain\Enums\PostStatus;
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

        $query = $this->listedDiscussions(DB::table('forum_posts as p')->join('forum_discussions as d', 'd.id', '=', 'p.discussion_id'), $categoryIds)
            // A shared character names itself, never its writer: whoever wrote it stays unknown
            ->leftJoin('forum_characters as c', 'c.id', '=', 'p.character_id')
            ->where(static function (Builder $where): void {
                $where->whereNull('c.id')->orWhereNotNull('c.owner_id');
            })
            ->where('p.status', PostStatus::Visible->value)
            ->whereNotNull('p.number')
            ->whereNotNull('p.published_at');

        return $this->rows($this->written($query, 'p.author_id'), 'p', $onlyIds, $afterId, $limit, 'p.published_at');
    }

    public function discussions(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array
    {
        if ($categoryIds === [] || $onlyIds === [] || $limit < 1) {
            return [];
        }

        // A discussion with no visible post (its first awaits approval, or every one was hidden) is not listed
        $query = $this->listedDiscussions(DB::table('forum_discussions as d'), $categoryIds)->where('d.post_count', '>', 0);

        return $this->rows($this->written($query, 'd.author_id'), 'd', $onlyIds, $afterId, $limit, 'd.created_at');
    }

    /**
     * @param  list<string>  $categoryIds
     */
    private function listedDiscussions(Builder $query, array $categoryIds): Builder
    {
        return $query->whereIn('d.category_id', $categoryIds)
            ->where('d.is_hidden', false)
            ->where('d.awaiting_approval', false)
            ->whereNotNull('d.number');
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
