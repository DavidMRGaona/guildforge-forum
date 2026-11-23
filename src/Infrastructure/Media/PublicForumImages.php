<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Media;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Activity\ActivityCategories;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use RuntimeException;

/**
 * Which forum images the media library may show (forum#155): those of what every signed-in member
 * reads. A post is read by every member while it is visible and published, its discussion is listed
 * (numbered, not hidden, not awaiting approval) and its category is one every member reads, by the
 * rule the activity contract counts with (ActivityCategories: anyone or any account, its parent too,
 * and nothing while the forum is a preview). Hidden, pending and deleted posts, and whatever sits in
 * a category for association members, a role or a campaign's roster, are not.
 *
 * Decided without the person who opens the library: it runs in the media sync and in queued
 * refreshes. What cannot be read throws, so a sync never takes a failure for «may be seen».
 */
final readonly class PublicForumImages
{
    public function __construct(
        private ActivityCategories $categories,
    ) {}

    /**
     * The categories every member reads now, to pass to the checks below.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the access setting or a category cannot be read
     */
    public function categoryIds(): array
    {
        return $this->categories->countedIds();
    }

    /**
     * @param  list<string>  $categoryIds  from categoryIds()
     */
    public function isPostRead(PostModel $post, ?DiscussionModel $discussion, array $categoryIds): bool
    {
        return $discussion instanceof DiscussionModel
            && $post->status === PostStatus::Visible->value
            && $post->number !== null
            && $post->published_at !== null
            && ! $discussion->is_hidden
            && ! $discussion->awaiting_approval
            && $discussion->number !== null
            && in_array(strtolower($discussion->category_id), $categoryIds, true);
    }

    /**
     * Whether any post every member reads speaks as the character: only there is its portrait
     * shown to others (one's own list of characters is one's own).
     *
     * @param  list<string>  $categoryIds  from categoryIds()
     */
    public function isCharacterSeen(string $characterId, array $categoryIds): bool
    {
        if ($categoryIds === []) {
            return false;
        }

        return DB::table('forum_posts as p')
            ->join('forum_discussions as d', 'd.id', '=', 'p.discussion_id')
            ->where('p.character_id', $characterId)
            ->where('p.status', PostStatus::Visible->value)
            ->whereNotNull('p.number')
            ->whereNotNull('p.published_at')
            ->where(static function (Builder $listed) use ($categoryIds): void {
                $listed->whereIn('d.category_id', $categoryIds)
                    ->where('d.is_hidden', false)
                    ->where('d.awaiting_approval', false)
                    ->whereNotNull('d.number');
            })
            ->exists();
    }
}
