<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * A person's activity in the forum for their public profile, within what the criteria let the
 * reader see. Every answer reads a handful of indexed rows.
 */
interface ProfileActivityQueryInterface
{
    /**
     * The characters the person's visible messages speak as, in the categories given.
     *
     * @param  list<string>  $categoryIds
     */
    public function charactersUsedBy(string $authorId, array $categoryIds): ProfileCharacterUseDTO;

    /**
     * The person's latest visible messages, newest first.
     *
     * @return list<ProfilePostRowDTO>
     */
    public function latestPosts(ProfileActivityCriteria $criteria, int $limit): array;

    public function countPosts(ProfileActivityCriteria $criteria): int;

    /**
     * The visible topics the person opened.
     */
    public function countTopics(ProfileActivityCriteria $criteria): int;
}
