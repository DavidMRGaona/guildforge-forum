<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\MentionCandidatesInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\DTOs\AccountSummaryDTO;
use Modules\Forum\Domain\Entities\Discussion;

/**
 * Candidates are looked up by name among mentionable accounts and then filtered by the
 * discussion's access rules, one account at a time, so only people who may read it appear.
 * A few more than needed are read, since some may be filtered out. The forum's system account
 * is never offered.
 */
final readonly class EloquentMentionCandidates implements MentionCandidatesInterface
{
    private const int OVERFETCH = 5;

    private const int MAX_QUERY_LENGTH = 50;

    public function __construct(
        private ForumAccessInterface $access,
        private SystemAccountInterface $systemAccount,
    ) {}

    public function search(Discussion $discussion, string $query, int $limit): array
    {
        $needle = mb_strtolower(mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH));
        if ($needle === '' || $limit < 1) {
            return [];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
        $name = 'LOWER(COALESCE(NULLIF(display_name, \'\'), name))';

        $found = [];
        foreach ([$escaped.'%', '%'.$escaped.'%'] as $pattern) {
            $users = UserModel::query()
                ->whereNull('anonymized_at')
                ->whereRaw("{$name} LIKE ? ESCAPE '\\'", [$pattern])
                ->whereKeyNot(array_keys($found))
                ->orderByRaw($name)
                ->limit($limit * self::OVERFETCH)
                ->get();

            foreach ($users as $user) {
                if (count($found) >= $limit) {
                    break 2;
                }
                if (! $this->systemAccount->isSystemAccount($user->id) && $this->access->canRead($user, $discussion)) {
                    $found[$user->id] = new AccountSummaryDTO(strtolower($user->id), EloquentAccountDirectory::nameOf($user), $user->getFilamentAvatarUrl());
                }
            }
        }

        return array_values($found);
    }
}
