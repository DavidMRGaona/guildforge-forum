<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\DTOs\ActivityHistoryPageDTO;
use App\Application\Activity\DTOs\ActivitySubjectFactsDTO;
use App\Domain\Activity\ActivityRole;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Forum\Application\Activity\ActivityCategories;
use Modules\Forum\Application\Queries\ActivitySubjectRowDTO;

/**
 * What the forum's two activity sources share (forum#151): one fact per subject, its author as
 * `author`, read the same for everybody (never the signed-in person: queued jobs read it), and a
 * history in id order whose cursor is the last id given, encoded with the source's type, so one
 * source's cursor is never read by the other.
 */
abstract readonly class ForumActivitySource implements ActivitySourceInterface
{
    public function __construct(
        protected ActivityCategories $categories,
    ) {}

    /**
     * @param  list<string>  $categoryIds
     * @param  list<string>|null  $onlyIds
     * @return list<ActivitySubjectRowDTO>
     */
    abstract protected function rows(array $categoryIds, ?array $onlyIds, ?string $afterId, int $limit): array;

    public function facts(string $subjectId): array
    {
        if (! Str::isUuid($subjectId)) {
            return [];
        }

        $rows = $this->rows($this->categories->countedIds(), [strtolower($subjectId)], null, 1);

        return $rows === [] ? [] : [self::fact($rows[0])];
    }

    public function history(?string $cursor, int $limit): ActivityHistoryPageDTO
    {
        $after = $cursor === null ? null : $this->readCursor($cursor);
        $limit = max(1, min($limit, ActivityHistoryPageDTO::MAX_LIMIT));

        // One more than asked tells whether another page follows
        $rows = $this->rows($this->categories->countedIds(), null, $after, $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $subjects = array_map(static fn (ActivitySubjectRowDTO $row): ActivitySubjectFactsDTO => new ActivitySubjectFactsDTO($row->id, [self::fact($row)]), $rows);
        $last = $rows === [] ? null : $rows[count($rows) - 1]->id;

        return new ActivityHistoryPageDTO($subjects, $more && $last !== null ? $this->cursorAfter($last) : null);
    }

    /**
     * The ids that may be a subject's: UUIDs, in lower case, each once.
     *
     * @param  list<string>  $subjectIds
     * @return list<string>
     */
    protected static function wellFormed(array $subjectIds): array
    {
        $ids = [];
        foreach ($subjectIds as $id) {
            if (Str::isUuid($id)) {
                $ids[strtolower($id)] = true;
            }
        }

        return array_keys($ids);
    }

    private static function fact(ActivitySubjectRowDTO $row): ActivityFactDTO
    {
        return new ActivityFactDTO($row->authorId, ActivityRole::Author, $row->occurredAt);
    }

    private function cursorAfter(string $id): string
    {
        return rtrim(strtr(base64_encode($this->type().':'.$id), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidArgumentException when it is not a cursor this source gave
     */
    private function readCursor(string $cursor): string
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $prefix = $this->type().':';

        if (! is_string($decoded) || ! str_starts_with($decoded, $prefix)) {
            throw new InvalidArgumentException("Unreadable {$this->type()} history cursor.");
        }

        $id = substr($decoded, strlen($prefix));
        if (! Str::isUuid($id) || $id !== strtolower($id)) {
            throw new InvalidArgumentException("Unreadable {$this->type()} history cursor.");
        }

        return $id;
    }
}
