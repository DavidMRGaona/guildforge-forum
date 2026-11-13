<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Queries\ConversationListQueryInterface;
use Modules\Forum\Application\Queries\ConversationRowDTO;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Infrastructure\Services\EloquentConversationParticipants;

/**
 * The person's conversations through their own participant rows: a conversation they are not in
 * cannot come out of here. Unread counts are messages by others after their last read entry.
 */
final readonly class EloquentConversationListQuery implements ConversationListQueryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function page(string $accountId, int $page, int $perPage): array
    {
        $account = AccountId::normalize($accountId);
        if ($account === null || $perPage < 1) {
            return ['rows' => [], 'total' => 0];
        }

        $base = $this->mine($account);
        $total = (clone $base)->count();

        $rows = $base
            ->select(['c.id', 'c.title', 'c.last_activity_at', 'p.read_through'])
            ->selectSub($this->unreadSub($account), 'unread')
            ->orderByDesc('c.last_activity_at')
            ->orderBy('c.id')
            ->forPage(max(1, $page), $perPage)
            ->get();

        $ids = $rows->pluck('id')->map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '')->all();
        $people = [];
        foreach (DB::table(EloquentConversationParticipants::TABLE)->whereIn('conversation_id', $ids)->orderBy('joined_at')->orderBy('user_id')->get(['conversation_id', 'user_id']) as $row) {
            $people[is_scalar($row->conversation_id) ? (string) $row->conversation_id : ''][] = strtolower(is_scalar($row->user_id) ? (string) $row->user_id : '');
        }

        $list = [];
        foreach ($rows as $row) {
            $id = is_scalar($row->id) ? (string) $row->id : '';
            $list[] = new ConversationRowDTO(
                id: $id,
                title: is_scalar($row->title) ? (string) $row->title : '',
                lastActivityAt: CarbonImmutable::parse(is_scalar($row->last_activity_at) ? (string) $row->last_activity_at : 'now', $this->timezone->storageTimezone())->toDateTimeImmutable(),
                unread: is_numeric($row->unread) ? (int) $row->unread : 0,
                participantIds: $people[$id] ?? [],
            );
        }

        return ['rows' => $list, 'total' => $total];
    }

    public function unreadCount(string $accountId): int
    {
        $account = AccountId::normalize($accountId);
        if ($account === null) {
            return 0;
        }

        return $this->mine($account)
            ->whereColumn('c.last_message_number', '>', 'p.read_through')
            ->whereExists(fn (Builder $query): Builder => $this->unreadMessages($query, $account))
            ->count();
    }

    private function mine(string $account): Builder
    {
        return DB::table('forum_conversations as c')
            ->join(EloquentConversationParticipants::TABLE.' as p', 'p.conversation_id', '=', 'c.id')
            ->where('p.user_id', $account);
    }

    private function unreadSub(string $account): Builder
    {
        return $this->unreadMessages(DB::table('forum_conversation_messages as m')->selectRaw('COUNT(*)'), $account, from: false);
    }

    private function unreadMessages(Builder $query, string $account, bool $from = true): Builder
    {
        if ($from) {
            $query->from('forum_conversation_messages as m');
        }

        return $query
            ->whereColumn('m.conversation_id', 'c.id')
            ->whereColumn('m.number', '>', 'p.read_through')
            ->where('m.kind', ConversationEntryKind::Message->value)
            ->where(static fn (Builder $author): Builder => $author->whereNull('m.author_id')->orWhere('m.author_id', '!=', $account));
    }
}
