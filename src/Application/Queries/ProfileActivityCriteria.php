<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * Whose activity a profile asks for, and what of it the person looking may see. The category ids
 * are the ones the reader may read (decided by the forum's access rules before asking). Only
 * visible messages of visible topics count. The writer rules apply outside the moderated
 * categories only, where they may hide a message: `hiddenCharacterIds` are the shared characters
 * whose writer the reader may not know, and `hidesCharacterPosts` leaves out every message the
 * person wrote as a character (or as one deleted since), as the forum's filter by author does.
 */
final readonly class ProfileActivityCriteria
{
    /**
     * @param  list<string>  $categoryIds  readable
     * @param  list<string>  $moderatedCategoryIds  of those, the ones the reader moderates
     * @param  list<string>  $hiddenCharacterIds
     */
    public function __construct(
        public string $authorId,
        public array $categoryIds,
        public array $moderatedCategoryIds,
        public array $hiddenCharacterIds = [],
        public bool $hidesCharacterPosts = false,
    ) {}
}
