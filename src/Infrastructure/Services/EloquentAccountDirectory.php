<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;

/**
 * Accounts read from the core's users. Deactivated accounts are soft deleted; anonymized ones
 * keep a row with a generic name.
 */
final class EloquentAccountDirectory implements AccountDirectoryInterface
{
    public function namesOf(array $accountIds): array
    {
        $ids = $this->valid($accountIds);
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (UserModel::query()->withTrashed()->whereKey($ids)->get(['id', 'name', 'display_name']) as $user) {
            $names[strtolower($user->id)] = self::nameOf($user);
        }

        return $names;
    }

    public function mentionable(array $accountIds): array
    {
        $ids = $this->valid($accountIds);
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => strtolower(is_scalar($id) ? (string) $id : ''),
            UserModel::query()->whereKey($ids)->whereNull('anonymized_at')->pluck('id')->all(),
        ));
    }

    public static function nameOf(UserModel $user): string
    {
        return $user->display_name !== null && $user->display_name !== '' ? $user->display_name : $user->name;
    }

    /**
     * @param  list<string>  $accountIds
     * @return list<string>
     */
    private function valid(array $accountIds): array
    {
        // A malformed uuid would make PostgreSQL throw
        return array_values(array_unique(array_filter(array_map(AccountId::normalize(...), $accountIds), is_string(...))));
    }
}
