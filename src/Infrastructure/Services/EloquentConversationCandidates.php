<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Contracts\ConversationCandidatesInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;

/**
 * People found by the name they show, those whose name starts with the text first. Deactivated
 * accounts (soft deleted) and anonymized ones are never found, nor the forum's system account.
 */
final readonly class EloquentConversationCandidates implements ConversationCandidatesInterface
{
    private const int MAX_QUERY_LENGTH = 50;

    public function __construct(
        private SystemAccountInterface $systemAccount,
    ) {}

    public function search(string $query, array $excluded, int $limit): array
    {
        $needle = mb_strtolower(mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH));
        if ($needle === '' || $limit < 1) {
            return [];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
        $name = 'LOWER(COALESCE(NULLIF(display_name, \'\'), name))';
        $skip = array_values(array_filter(array_map(AccountId::normalize(...), $excluded), is_string(...)));

        $found = [];
        foreach ([$escaped.'%', '%'.$escaped.'%'] as $pattern) {
            $users = UserModel::query()
                ->whereNull('anonymized_at')
                ->whereRaw("{$name} LIKE ? ESCAPE '\\'", [$pattern])
                ->whereKeyNot([...$skip, ...array_keys($found)])
                ->orderByRaw($name)
                ->limit($limit + 1)
                ->get(['id', 'name', 'display_name']);

            foreach ($users as $user) {
                if (count($found) >= $limit) {
                    break 2;
                }
                if (! $this->systemAccount->isSystemAccount($user->id)) {
                    $found[strtolower($user->id)] = ['id' => strtolower($user->id), 'name' => EloquentAccountDirectory::nameOf($user)];
                }
            }
        }

        return array_values($found);
    }
}
