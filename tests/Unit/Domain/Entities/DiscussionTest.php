<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use PHPUnit\Framework\TestCase;

final class DiscussionTest extends TestCase
{
    public function test_a_new_discussion_is_open_unpinned_and_shown(): void
    {
        $discussion = $this->discussion();

        $this->assertSame(DiscussionStatus::Open, $discussion->status());
        $this->assertFalse($discussion->isPinned());
        $this->assertFalse($discussion->isHidden());
        $this->assertTrue($discussion->acceptsReplies());
    }

    public function test_closing_stops_replies_and_reopening_allows_them_again(): void
    {
        $discussion = $this->discussion();

        $discussion->close();
        $this->assertSame(DiscussionStatus::Closed, $discussion->status());
        $this->assertFalse($discussion->acceptsReplies());

        $discussion->reopen();
        $this->assertTrue($discussion->acceptsReplies());
    }

    public function test_a_closed_discussion_is_not_closed_again(): void
    {
        $discussion = $this->discussion();
        $discussion->close();

        $this->expectException(InvalidDiscussionException::class);

        $discussion->close();
    }

    public function test_an_open_discussion_is_not_reopened(): void
    {
        $this->expectException(InvalidDiscussionException::class);

        $this->discussion()->reopen();
    }

    public function test_pinning_and_closing_combine(): void
    {
        // A rules thread is pinned and closed at once
        $discussion = $this->discussion();

        $discussion->pin();
        $discussion->close();

        $this->assertTrue($discussion->isPinned());
        $this->assertSame(DiscussionStatus::Closed, $discussion->status());

        $discussion->unpin();
        $this->assertFalse($discussion->isPinned());
    }

    public function test_a_hidden_discussion_takes_no_replies_until_shown_again(): void
    {
        $discussion = $this->discussion();

        $discussion->hide();
        $this->assertTrue($discussion->isHidden());
        $this->assertFalse($discussion->acceptsReplies());

        $discussion->unhide();
        $this->assertFalse($discussion->isHidden());
        $this->assertTrue($discussion->acceptsReplies());
    }

    public function test_renaming_and_moving(): void
    {
        $discussion = $this->discussion();
        $category = CategoryId::generate();

        $discussion->rename(new DiscussionTitle('Nuevo título'));
        $discussion->moveTo($category);

        $this->assertSame('Nuevo título', $discussion->title()->value);
        $this->assertTrue($discussion->categoryId()->equals($category));
    }

    public function test_the_author_is_a_lowercase_account_id(): void
    {
        $discussion = Discussion::start(DiscussionId::generate(), CategoryId::generate(), 'A1B2C3D4-0000-4000-8000-000000000001', new DiscussionTitle('Hola'), new DateTimeImmutable);

        $this->assertSame('a1b2c3d4-0000-4000-8000-000000000001', $discussion->authorId());
    }

    public function test_the_author_is_a_valid_account_id(): void
    {
        $this->expectException(InvalidDiscussionException::class);

        Discussion::start(DiscussionId::generate(), CategoryId::generate(), 'nadie', new DiscussionTitle('Hola'), new DateTimeImmutable);
    }

    public function test_a_discussion_whose_first_post_awaits_approval_waits_with_it(): void
    {
        $discussion = $this->discussion();
        $this->assertFalse($discussion->isAwaitingApproval());

        $discussion->awaitApproval();

        $this->assertTrue($discussion->isAwaitingApproval());
        $this->assertFalse($discussion->acceptsReplies());

        $discussion->approve();

        $this->assertFalse($discussion->isAwaitingApproval());
        $this->assertTrue($discussion->acceptsReplies());
    }

    public function test_only_a_waiting_discussion_is_approved(): void
    {
        $this->expectException(InvalidDiscussionException::class);

        $this->discussion()->approve();
    }

    public function test_a_title_moderation_gave_is_marked_as_theirs(): void
    {
        $discussion = $this->discussion();
        $discussion->rename(new DiscussionTitle('Del sistema'));
        $this->assertFalse($discussion->isTitleSetByModeration());

        $discussion->renameByModeration(new DiscussionTitle('De la moderación'));

        $this->assertSame('De la moderación', $discussion->title()->value);
        $this->assertTrue($discussion->isTitleSetByModeration());
    }

    private function discussion(): Discussion
    {
        return Discussion::start(DiscussionId::generate(), CategoryId::generate(), null, new DiscussionTitle('Sesión del viernes'), new DateTimeImmutable('2026-11-10 20:00:00'));
    }
}
