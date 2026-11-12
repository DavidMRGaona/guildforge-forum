<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\AccountEngagementStoreInterface;
use Modules\Forum\Application\Contracts\MentionTextsInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;

/**
 * What the forum does when an account is anonymized (core#461), all in one transaction and safe
 * to repeat:
 *
 * - Posts and discussions stay: they belong to other people's conversations. They show the
 *   site's anonymous name because the forum reads names from the account when it paints, and so
 *   do the mentions of the person; no avatar is ever shown next to a post.
 * - Gone: likes, follows, reading marks, preferences and the reports the person filed. The core
 *   already removed their notices, mail choices and muted discussions.
 * - Characters lose their description and portrait, and are retired by moderation, so nobody can
 *   use them again, not even if the account is restored. Their names stay on the posts.
 * - Kept as moderation history: the reports their posts received, their suspensions and the log.
 * - The forum's system account is never touched from here; if the core anonymizes it, the forum
 *   makes a new one when it next needs it.
 */
final readonly class AccountErasure
{
    public function __construct(
        private AccountEngagementStoreInterface $engagement,
        private CharacterRepositoryInterface $characters,
        private SystemAccountInterface $systemAccount,
        private MentionTextsInterface $mentions,
    ) {}

    public function forget(string $accountId): void
    {
        if ($this->systemAccount->isSystemAccount($accountId)) {
            return;
        }

        DB::transaction(function () use ($accountId): void {
            $this->engagement->forget($accountId);
            $this->mentions->refreshFor($accountId);
            $this->closeCharacters($accountId, new DateTimeImmutable);
        });
    }

    private function closeCharacters(string $accountId, DateTimeImmutable $at): void
    {
        foreach ($this->characters->ownedBy($accountId) as $character) {
            $character->stripPersonalDetails($at);
            if (! $character->isRetired() || ! $character->isRetirementLocked()) {
                $character->retire($at, byModeration: true);
            }

            $this->characters->save($character);
        }
    }
}
