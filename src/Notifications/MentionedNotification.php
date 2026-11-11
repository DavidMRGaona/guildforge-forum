<?php

declare(strict_types=1);

namespace Modules\Forum\Notifications;

use Modules\Forum\Application\DTOs\PostNoticeDTO;
use Modules\Forum\Domain\Enums\PostNoticeKind;

/**
 * Someone mentioned the person, or quoted one of their posts. Sent at once, never gathered.
 */
final class MentionedNotification extends PostNotification
{
    public function __construct(
        string $postId,
        public readonly PostNoticeKind $kind,
    ) {
        parent::__construct($postId);
    }

    public function mailCategory(): string
    {
        return ForumMailCategories::MENTIONS;
    }

    protected function title(PostNoticeDTO $notice): string
    {
        $key = $this->kind === PostNoticeKind::Quote ? 'quote' : 'mention';

        return __("forum::messages.notices.{$key}.title", ['author' => self::authorOf($notice), 'discussion' => $notice->discussion->title()->value]);
    }

    protected function icon(): string
    {
        return $this->kind === PostNoticeKind::Quote ? 'quote' : 'at-sign';
    }
}
