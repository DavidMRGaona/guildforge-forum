<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use Illuminate\Database\Query\Builder;
use Modules\Forum\Domain\Enums\PostStatus;

/**
 * What every signed-in member reads, as query conditions: the one place both the activity contract
 * (forum#151) and the media library (forum#155) decide it. A discussion is listed while it is
 * numbered, not hidden and not awaiting approval, in one of the categories every member reads (the
 * caller passes them, from ActivityCategories); a post is read while it is visible, numbered and
 * published, in such a discussion.
 */
final class EveryMemberReads
{
    /**
     * Keeps the discussions (`$discussion` is their table or alias) every member reads.
     *
     * @param  list<string>  $categoryIds
     */
    public static function discussions(Builder $query, string $discussion, array $categoryIds): Builder
    {
        return $query->whereIn("{$discussion}.category_id", $categoryIds)
            ->where("{$discussion}.is_hidden", false)
            ->where("{$discussion}.awaiting_approval", false)
            ->whereNotNull("{$discussion}.number");
    }

    /**
     * Keeps the posts (`$post`) that are read wherever their discussion is: visible, numbered and published.
     */
    public static function posts(Builder $query, string $post): Builder
    {
        return $query->where("{$post}.status", PostStatus::Visible->value)
            ->whereNotNull("{$post}.number")
            ->whereNotNull("{$post}.published_at");
    }

    /**
     * Posts every member reads, joined to their discussion as `d` (`$post` is the posts' alias).
     *
     * @param  list<string>  $categoryIds
     */
    public static function postsInListedDiscussions(Builder $query, string $post, array $categoryIds): Builder
    {
        return self::discussions(
            self::posts($query->join('forum_discussions as d', 'd.id', '=', "{$post}.discussion_id"), $post),
            'd',
            $categoryIds,
        );
    }
}
