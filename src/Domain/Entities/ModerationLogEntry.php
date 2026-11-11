<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\ValueObjects\AccountId;

/**
 * One thing moderation did: who, to whom, where, why and when. Entries are only ever added.
 * The discussion and post are kept by id alone (they may be deleted later), so details holds
 * what is needed to read the entry without them (a title, the categories of a move).
 */
final readonly class ModerationLogEntry
{
    public const int MAX_REASON_LENGTH = 500;

    public ?string $moderatorId;

    public ?string $subjectUserId;

    public ?string $reason;

    /**
     * @param  string|null  $moderatorId  who did it; null when the account is gone
     * @param  string|null  $subjectUserId  whose discussion or post it was
     * @param  array<string, string|int|bool|null>  $details
     *
     * @throws InvalidArgumentException for an account id that is not a UUID
     */
    public function __construct(
        public string $id,
        public ModerationAction $action,
        ?string $moderatorId,
        ?string $subjectUserId,
        public ?string $categoryId,
        public ?string $discussionId,
        public ?string $postId,
        ?string $reason,
        public array $details,
        public DateTimeImmutable $at,
    ) {
        $this->moderatorId = self::account($moderatorId);
        $this->subjectUserId = self::account($subjectUserId);

        $reason = $reason === null ? '' : trim($reason);
        $this->reason = $reason === '' ? null : mb_substr($reason, 0, self::MAX_REASON_LENGTH);
    }

    private static function account(?string $id): ?string
    {
        return $id === null ? null : (AccountId::normalize($id) ?? throw new InvalidArgumentException("Not an account id: {$id}"));
    }
}
