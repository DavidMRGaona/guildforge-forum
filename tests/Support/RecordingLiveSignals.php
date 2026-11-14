<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Keeps which numbered posts open pages were told changed, as discussion id and number.
 */
final class RecordingLiveSignals implements ForumLiveSignalsInterface
{
    /** @var list<array{string, int}> */
    public array $changed = [];

    public function postPublished(Post $post): void {}

    public function postChanged(Post $post): void
    {
        $number = $post->number()?->value;
        if ($number !== null) {
            $this->changed[] = [$post->discussionId()->value, $number];
        }
    }

    public function discussionChanged(DiscussionId $discussionId): void {}

    public function conversationEntryAdded(ConversationId $conversationId, int $number, ?string $authorId): void {}

    public function conversationEntryChanged(ConversationId $conversationId, int $number): void {}

    public function pulseChanged(string $accountId): void {}
}
