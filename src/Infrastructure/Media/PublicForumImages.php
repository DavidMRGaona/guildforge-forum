<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Media;

use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Activity\ActivityCategories;
use Modules\Forum\Infrastructure\Queries\EveryMemberReads;
use RuntimeException;

/**
 * Which forum images the media library may show (forum#155): those of what every signed-in member
 * reads, by the rule the activity contract counts with: the categories of ActivityCategories (anyone
 * or any account, its parent too, and nothing while the forum is a preview) and the posts and
 * discussions of EveryMemberReads. Hidden, pending and deleted posts, hidden discussions or ones
 * awaiting approval, and whatever sits in a category for association members, a role or a
 * campaign's roster, are not.
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
     * Which of the posts every member reads, in one query.
     *
     * @param  list<string>  $postIds
     * @param  list<string>  $categoryIds  from categoryIds()
     * @return array<string, true> ids in lower case
     */
    public function readPostIds(array $postIds, array $categoryIds): array
    {
        if ($postIds === [] || $categoryIds === []) {
            return [];
        }

        $ids = EveryMemberReads::postsInListedDiscussions(DB::table('forum_posts as p'), 'p', $categoryIds)
            ->whereIn('p.id', array_map(strtolower(...), $postIds))
            ->pluck('p.id');

        $read = [];
        foreach ($ids as $id) {
            $read[strtolower((string) $id)] = true;
        }

        return $read;
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

        return EveryMemberReads::postsInListedDiscussions(DB::table('forum_posts as p'), 'p', $categoryIds)
            ->where('p.character_id', $characterId)
            ->exists();
    }
}
