<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Conversations;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ConversationCandidatesInterface;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Application\Contracts\UserBlocksInterface;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Throwable;

/**
 * Who the person may pick when starting a conversation or adding someone: people who may take
 * part, never the person, those already in the conversation, nor those who blocked them (so a
 * block reads as nobody being found). At least two letters, at most ten people.
 */
final readonly class ConversationPeople
{
    public const int MIN_QUERY_LENGTH = 2;

    public const int LIMIT = 10;

    public function __construct(
        private ConversationCandidatesInterface $candidates,
        private ConversationParticipantsInterface $participants,
        private UserBlocksInterface $blocks,
        private ConversationAccess $access,
    ) {}

    /**
     * @param  ConversationId|null  $conversation  the conversation someone is being added to, which the person must be in
     * @return list<array{id: string, name: string}>
     */
    public function search(Authenticatable $user, string $query, ?ConversationId $conversation = null): array
    {
        $me = ForumBrowser::accountId($user);
        if ($me === null || mb_strlen(trim($query)) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        try {
            $excluded = [$me, ...$this->blocks->allBlockersOf($me)];
            if ($conversation instanceof ConversationId) {
                if (! $this->access->canRead($user, $conversation)) {
                    return [];
                }
                array_push($excluded, ...$this->participants->of($conversation));
            }

            $found = [];
            // A few more than shown, since some may be suspended
            foreach ($this->candidates->search($query, $excluded, self::LIMIT * 2) as $person) {
                if (count($found) < self::LIMIT && $this->access->mayTakePart($person['id'])) {
                    $found[] = $person;
                }
            }

            return $found;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
