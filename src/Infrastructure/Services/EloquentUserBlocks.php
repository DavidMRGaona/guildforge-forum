<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\UserBlocksInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;

/**
 * Blocks in `forum_user_blocks`, one row per pair and direction.
 */
final readonly class EloquentUserBlocks implements UserBlocksInterface
{
    public const string TABLE = 'forum_user_blocks';

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function block(string $blockerId, string $blockedId, DateTimeImmutable $at): void
    {
        $blocker = AccountId::normalize($blockerId);
        $blocked = AccountId::normalize($blockedId);
        if ($blocker === null || $blocked === null || $blocker === $blocked) {
            return;
        }

        DB::table(self::TABLE)->insertOrIgnore([
            'blocker_user_id' => $blocker,
            'blocked_user_id' => $blocked,
            'created_at' => $this->timezone->toStorage($at)->format('Y-m-d H:i:s'),
        ]);
    }

    public function unblock(string $blockerId, string $blockedId): void
    {
        $blocker = AccountId::normalize($blockerId);
        $blocked = AccountId::normalize($blockedId);
        if ($blocker !== null && $blocked !== null) {
            DB::table(self::TABLE)->where('blocker_user_id', $blocker)->where('blocked_user_id', $blocked)->delete();
        }
    }

    public function blockedBy(string $blockerId): array
    {
        $blocker = AccountId::normalize($blockerId);
        if ($blocker === null) {
            return [];
        }

        $rows = DB::table(self::TABLE)->where('blocker_user_id', $blocker)->orderByDesc('created_at')->get(['blocked_user_id', 'created_at']);

        $blocks = [];
        foreach ($rows as $row) {
            $id = is_scalar($row->blocked_user_id) ? strtolower((string) $row->blocked_user_id) : '';
            $at = is_string($row->created_at) ? $row->created_at : '';
            $blocks[] = ['id' => $id, 'at' => new DateTimeImmutable($at === '' ? 'now' : $at, $this->timezone->storageTimezone())];
        }

        return $blocks;
    }

    public function blockersOf(string $blockedId, array $among): array
    {
        $blocked = AccountId::normalize($blockedId);
        $among = array_values(array_unique(array_filter(array_map(AccountId::normalize(...), $among), is_string(...))));
        if ($blocked === null || $among === []) {
            return [];
        }

        return $this->ids(DB::table(self::TABLE)->where('blocked_user_id', $blocked)->whereIn('blocker_user_id', $among)->pluck('blocker_user_id')->all());
    }

    public function allBlockersOf(string $blockedId): array
    {
        $blocked = AccountId::normalize($blockedId);

        return $blocked === null ? [] : $this->ids(DB::table(self::TABLE)->where('blocked_user_id', $blocked)->pluck('blocker_user_id')->all());
    }

    public function forget(string $accountId): void
    {
        $account = AccountId::normalize($accountId);
        if ($account !== null) {
            DB::table(self::TABLE)->where('blocker_user_id', $account)->orWhere('blocked_user_id', $account)->delete();
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function ids(array $values): array
    {
        return array_values(array_map(static fn (mixed $value): string => strtolower(is_scalar($value) ? (string) $value : ''), $values));
    }
}
