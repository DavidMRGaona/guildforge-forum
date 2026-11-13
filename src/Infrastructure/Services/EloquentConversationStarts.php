<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ConversationStartsInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Ramsey\Uuid\Uuid;

/**
 * Starts in `forum_conversation_starts`. Bounds go in the storage zone (bindings skip the
 * models' zone handling).
 */
final readonly class EloquentConversationStarts implements ConversationStartsInterface
{
    public const string TABLE = 'forum_conversation_starts';

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function lockFor(string $accountId): void
    {
        $account = AccountId::normalize($accountId);
        if ($account !== null) {
            // The account's row: SQLite locks the whole database for a write transaction anyway
            DB::table('users')->where('id', $account)->lockForUpdate()->first();
        }
    }

    public function record(string $accountId, DateTimeImmutable $at): void
    {
        $account = AccountId::normalize($accountId);
        if ($account !== null) {
            DB::table(self::TABLE)->insert(['id' => Uuid::uuid4()->toString(), 'user_id' => $account, 'created_at' => $this->stored($at)]);
        }
    }

    public function countSince(string $accountId, DateTimeImmutable $since): int
    {
        $account = AccountId::normalize($accountId);

        return $account === null ? 0 : DB::table(self::TABLE)->where('user_id', $account)->where('created_at', '>', $this->stored($since))->count();
    }

    public function oldestSince(string $accountId, DateTimeImmutable $since): ?DateTimeImmutable
    {
        $account = AccountId::normalize($accountId);
        $oldest = $account === null ? null : DB::table(self::TABLE)->where('user_id', $account)->where('created_at', '>', $this->stored($since))->min('created_at');

        return is_string($oldest) ? new DateTimeImmutable($oldest, $this->timezone->storageTimezone()) : null;
    }

    public function forget(string $accountId): void
    {
        $account = AccountId::normalize($accountId);
        if ($account !== null) {
            DB::table(self::TABLE)->where('user_id', $account)->delete();
        }
    }

    private function stored(DateTimeImmutable $at): string
    {
        return $this->timezone->toStorage($at)->format('Y-m-d H:i:s');
    }
}
