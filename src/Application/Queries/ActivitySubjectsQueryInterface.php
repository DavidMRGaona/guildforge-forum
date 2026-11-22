<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * The posts and discussions that count as activity (forum#151), read the same for everybody: never
 * for the signed-in person, as they are read in queued jobs. Only what any signed-in member may
 * read counts:
 *
 * - a post that is visible and numbered, in a visible, numbered discussion not awaiting approval,
 *   written by an account (never the forum's system account) and not as a shared character, which
 *   names itself and never its writer; as a personal character (or one deleted since) only when
 *   the writer has a public name, the only name the forum shows them by (CharacterWriterNames);
 * - a discussion that is visible, numbered, not awaiting approval and holds a visible post,
 *   opened by an account (never the system account), and not as a personal character by someone
 *   without a public name;
 * - both only in the categories given (those every signed-in member reads: ActivityCategories).
 *
 * Rows come in id order, from the one after `$afterId`, `$limit` at most.
 */
interface ActivitySubjectsQueryInterface
{
    /**
     * @param  list<string>  $categoryIds
     * @param  list<string>|null  $onlyIds  lower-case UUIDs; null for any
     * @return list<ActivitySubjectRowDTO>
     */
    public function posts(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array;

    /**
     * @param  list<string>  $categoryIds
     * @param  list<string>|null  $onlyIds  lower-case UUIDs; null for any
     * @return list<ActivitySubjectRowDTO>
     */
    public function discussions(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array;
}
