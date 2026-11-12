<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\DiscussionCarryOverInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Copies rows of `forum_discussion_reads` and `forum_discussion_follows` between discussions.
 * Rows already in the discussion they go to are left as they are: a person's own place and
 * choice there come first. Reply mails start afresh (mailed_through empty).
 */
final readonly class EloquentDiscussionCarryOver implements DiscussionCarryOverInterface
{
    private const int CHUNK = 500;

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function afterSplit(DiscussionId $from, DiscussionId $to, array $numbers, DateTimeImmutable $at): void
    {
        ksort($numbers);
        $at = $this->stored($at);

        $reads = [];
        foreach ($this->readsOf($from) as $userId => $lastRead) {
            $through = $this->contiguousThrough($numbers, $lastRead);
            if ($through > 0) {
                $reads[] = ['user_id' => $userId, 'discussion_id' => $to->value, 'last_read_number' => $through, 'read_at' => $at];
            }
        }
        $this->insert(EloquentDiscussionReads::DISCUSSIONS, $reads);

        $this->copyFollows($from, $to, $at);
    }

    public function afterMerge(DiscussionId $absorbed, DiscussionId $into, array $numbers, int $intoHighest, DateTimeImmutable $at): void
    {
        $at = $this->stored($at);
        $readInto = $this->readsOf($into);

        foreach ($this->readsOf($absorbed) as $userId => $lastRead) {
            if (($readInto[$userId] ?? 0) < $intoHighest) {
                continue;
            }

            $through = $this->contiguousThrough($numbers, $lastRead);
            if ($through > 0) {
                DB::table(EloquentDiscussionReads::DISCUSSIONS)
                    ->where('user_id', $userId)
                    ->where('discussion_id', $into->value)
                    ->where('last_read_number', '<', $through)
                    ->update(['last_read_number' => $through, 'read_at' => $at]);
            }
        }

        $this->copyFollows($absorbed, $into, $at);
    }

    /**
     * How far someone who read the old numbers up to `$lastRead` has read in the discussion the
     * posts went to: the new numbers in a row, from the first, whose old number they had read.
     *
     * @param  array<int, int>  $numbers  new number by old number
     */
    private function contiguousThrough(array $numbers, int $lastRead): int
    {
        $byNew = array_flip($numbers);
        ksort($byNew);

        $through = 0;
        foreach ($byNew as $new => $old) {
            if ($old > $lastRead) {
                break;
            }
            $through = $new;
        }

        return $through;
    }

    /**
     * @return array<string, int> how far each person read, by account id
     */
    private function readsOf(DiscussionId $discussionId): array
    {
        $reads = [];
        foreach (DB::table(EloquentDiscussionReads::DISCUSSIONS)->where('discussion_id', $discussionId->value)->get(['user_id', 'last_read_number']) as $row) {
            $reads[(string) $row->user_id] = (int) $row->last_read_number;
        }

        return $reads;
    }

    private function copyFollows(DiscussionId $from, DiscussionId $to, string $at): void
    {
        $rows = [];
        foreach (DB::table(EloquentFollows::DISCUSSIONS)->where('discussion_id', $from->value)->get(['user_id', 'level']) as $row) {
            $rows[] = ['user_id' => (string) $row->user_id, 'discussion_id' => $to->value, 'level' => (string) $row->level, 'mailed_through' => null, 'updated_at' => $at];
        }

        $this->insert(EloquentFollows::DISCUSSIONS, $rows);
    }

    /**
     * Ignored where the row exists already, so no failed insert aborts the surrounding transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }
    }

    private function stored(DateTimeImmutable $at): string
    {
        return $this->timezone->toStorage($at)->format('Y-m-d H:i:s');
    }
}
