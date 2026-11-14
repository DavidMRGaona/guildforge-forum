<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Modules\Forum\Domain\Repositories\MemberTitleRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\MemberTitle;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\MemberTitleModel;

final class EloquentMemberTitleRepository implements MemberTitleRepositoryInterface
{
    public function find(string $userId): ?MemberTitle
    {
        $id = AccountId::normalize($userId);
        $title = $id === null ? null : MemberTitleModel::query()->whereKey($id)->value('title');

        return is_string($title) ? MemberTitle::fromInput($title) : null;
    }

    public function titlesOf(array $userIds): array
    {
        // A malformed uuid would make PostgreSQL throw
        $ids = array_values(array_unique(array_filter(array_map(AccountId::normalize(...), $userIds), is_string(...))));
        if ($ids === []) {
            return [];
        }

        $titles = [];
        $rows = MemberTitleModel::query()
            ->join('users', 'users.id', '=', 'forum_member_titles.user_id')
            ->whereIn('forum_member_titles.user_id', $ids)
            ->whereNull('users.deleted_at')
            ->whereNull('users.anonymized_at')
            ->get(['forum_member_titles.user_id', 'forum_member_titles.title']);
        foreach ($rows as $row) {
            $titles[strtolower($row->user_id)] = $row->title;
        }

        return $titles;
    }

    public function save(string $userId, MemberTitle $title, string $moderatorId, DateTimeImmutable $at): void
    {
        $model = MemberTitleModel::query()->find($userId) ?? new MemberTitleModel(['user_id' => $userId, 'created_at' => $at]);
        $model->fill(['title' => $title->value, 'moderator_id' => $moderatorId, 'updated_at' => $at]);
        $model->timestamps = false;
        $model->save();
    }

    public function forget(string $userId): void
    {
        MemberTitleModel::query()->whereKey($userId)->delete();
    }
}
