<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\ConversationId;

/**
 * Participants in `forum_conversation_participants`. An id that is not a UUID is in no
 * conversation, so a malformed one never reaches the database.
 */
final readonly class EloquentConversationParticipants implements ConversationParticipantsInterface
{
    public const string TABLE = 'forum_conversation_participants';

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function of(ConversationId $id): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => strtolower(is_scalar($value) ? (string) $value : ''),
            DB::table(self::TABLE)->where('conversation_id', $id->value)->orderBy('joined_at')->orderBy('user_id')->pluck('user_id')->all(),
        ));
    }

    public function isIn(ConversationId $id, string $accountId): bool
    {
        return $this->readThrough($id, $accountId) !== null;
    }

    public function add(ConversationId $id, string $accountId, int $readThrough, DateTimeImmutable $at): void
    {
        $account = AccountId::normalize($accountId);
        if ($account === null) {
            return;
        }

        DB::table(self::TABLE)->insertOrIgnore([
            'conversation_id' => $id->value,
            'user_id' => $account,
            'read_through' => max(0, $readThrough),
            'mailed_through' => null,
            'joined_at' => $this->timezone->toStorage($at)->format('Y-m-d H:i:s'),
        ]);
    }

    public function remove(ConversationId $id, string $accountId): void
    {
        $account = AccountId::normalize($accountId);
        if ($account !== null) {
            DB::table(self::TABLE)->where('conversation_id', $id->value)->where('user_id', $account)->delete();
        }
    }

    public function readThrough(ConversationId $id, string $accountId): ?int
    {
        $account = AccountId::normalize($accountId);
        if ($account === null) {
            return null;
        }

        $value = DB::table(self::TABLE)->where('conversation_id', $id->value)->where('user_id', $account)->value('read_through');

        return is_numeric($value) ? (int) $value : null;
    }

    public function markRead(ConversationId $id, string $accountId, int $number): void
    {
        $account = AccountId::normalize($accountId);
        if ($account === null) {
            return;
        }

        DB::table(self::TABLE)
            ->where('conversation_id', $id->value)
            ->where('user_id', $account)
            ->where('read_through', '<', $number)
            ->update(['read_through' => $number]);
    }

    public function claimMail(ConversationId $id, string $accountId, int $number): bool
    {
        $account = AccountId::normalize($accountId);
        if ($account === null) {
            return false;
        }

        // One conditional update: two messages delivered at once claim one mail between them
        return DB::table(self::TABLE)
            ->where('conversation_id', $id->value)
            ->where('user_id', $account)
            ->where('read_through', '<', $number)
            ->where(static function ($query): void {
                $query->whereNull('mailed_through')->orWhereColumn('mailed_through', '<=', 'read_through');
            })
            ->update(['mailed_through' => $number]) > 0;
    }

    public function conversationsOf(string $accountId): array
    {
        $account = AccountId::normalize($accountId);
        if ($account === null) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $value): ConversationId => ConversationId::fromString(is_scalar($value) ? (string) $value : ''),
            DB::table(self::TABLE)->where('user_id', $account)->pluck('conversation_id')->all(),
        ));
    }
}
