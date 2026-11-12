<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use PHPUnit\Framework\TestCase;

final class PostTest extends TestCase
{
    public function test_a_published_post_is_visible_with_its_number(): void
    {
        $post = $this->published(3);

        $this->assertSame(PostStatus::Visible, $post->status());
        $this->assertSame(3, $post->number()?->value);
        $this->assertTrue($post->isVisible());
        $this->assertEquals($post->createdAt(), $post->publishedAt());
        $this->assertNull($post->editedAt());
    }

    public function test_a_post_awaiting_approval_has_no_number_yet(): void
    {
        $post = $this->pending();

        $this->assertSame(PostStatus::Pending, $post->status());
        $this->assertNull($post->number());
        $this->assertNull($post->publishedAt());
        $this->assertFalse($post->isVisible());
    }

    public function test_approving_gives_the_post_its_number(): void
    {
        $post = $this->pending();

        $at = new DateTimeImmutable('2026-11-10 22:00:00');

        $post->approve(new PostNumber(4), $at);

        $this->assertSame(PostStatus::Visible, $post->status());
        $this->assertSame(4, $post->number()?->value);
        // It reads from its approval, after what was published while it waited
        $this->assertEquals($at, $post->publishedAt());
        $this->assertEquals(new DateTimeImmutable('2026-11-10 20:00:00'), $post->createdAt());
    }

    public function test_only_a_pending_post_is_approved(): void
    {
        $this->expectException(InvalidPostException::class);

        $this->published(1)->approve(new PostNumber(2), new DateTimeImmutable);
    }

    public function test_hiding_keeps_the_number_so_readers_see_no_gap(): void
    {
        $post = $this->published(2);

        $post->hide();

        $this->assertSame(PostStatus::Hidden, $post->status());
        $this->assertSame(2, $post->number()?->value);
        $this->assertFalse($post->isVisible());
    }

    public function test_hiding_keeps_the_reason_until_it_is_shown_again(): void
    {
        $post = $this->published(2);

        $post->hide('  Insultos  ');

        $this->assertSame('Insultos', $post->hiddenReason());

        $post->restore();

        $this->assertNull($post->hiddenReason());
    }

    public function test_a_blank_reason_is_no_reason(): void
    {
        $post = $this->published(2);

        $post->hide('   ');

        $this->assertNull($post->hiddenReason());
    }

    public function test_a_reason_too_long_is_refused(): void
    {
        $this->expectException(InvalidPostException::class);

        $this->published(2)->hide(str_repeat('a', Post::MAX_HIDDEN_REASON_LENGTH + 1));
    }

    public function test_only_a_hidden_post_keeps_a_reason(): void
    {
        $this->expectException(InvalidPostException::class);

        new Post(PostId::generate(), DiscussionId::generate(), new PostNumber(1), null, $this->body(), PostStatus::Visible, new DateTimeImmutable, new DateTimeImmutable, hiddenReason: 'Spam');
    }

    public function test_a_hidden_post_is_restored(): void
    {
        $post = $this->published(2);
        $post->hide();

        $post->restore();

        $this->assertSame(PostStatus::Visible, $post->status());
    }

    public function test_only_a_hidden_post_is_restored(): void
    {
        $this->expectException(InvalidPostException::class);

        $this->published(1)->restore();
    }

    public function test_a_pending_post_is_never_hidden(): void
    {
        // It has no number: a moderator rejects it instead, and it goes away
        $this->expectException(InvalidPostException::class);

        $this->pending()->hide();
    }

    public function test_its_author_deletes_a_visible_or_hidden_post_and_the_number_stays(): void
    {
        $visible = $this->published(1);
        $hidden = $this->published(2);
        $hidden->hide();

        $visible->deleteByAuthor(new DateTimeImmutable);
        $hidden->deleteByAuthor(new DateTimeImmutable);

        $this->assertSame(PostStatus::Deleted, $visible->status());
        $this->assertSame(PostStatus::Deleted, $hidden->status());
        $this->assertSame(2, $hidden->number()?->value);
    }

    public function test_a_deleted_post_stays_deleted(): void
    {
        $post = $this->published(1);
        $post->deleteByAuthor(new DateTimeImmutable);

        $this->expectException(InvalidPostException::class);

        $post->hide();
    }

    public function test_a_pending_post_is_withdrawn_rather_than_deleted(): void
    {
        $this->expectException(InvalidPostException::class);

        $this->pending()->deleteByAuthor(new DateTimeImmutable);
    }

    public function test_editing_replaces_the_body_and_records_when(): void
    {
        $post = $this->published(1);
        $body = new PostBody(['type' => 'doc', 'content' => []], 'Corregido');
        $at = new DateTimeImmutable('2026-11-10 21:00:00');

        $post->edit($body, $at, null);

        $this->assertSame('Corregido', $post->body()->plainText);
        $this->assertEquals($at, $post->editedAt());
    }

    public function test_a_deleted_post_is_never_edited(): void
    {
        $post = $this->published(1);
        $post->deleteByAuthor(new DateTimeImmutable);

        $this->expectException(InvalidPostException::class);

        $post->edit(new PostBody(['type' => 'doc', 'content' => []], 'Otra vez'), new DateTimeImmutable, null);
    }

    public function test_a_visible_post_always_has_a_number(): void
    {
        $this->expectException(InvalidPostException::class);

        new Post(PostId::generate(), DiscussionId::generate(), null, null, $this->body(), PostStatus::Visible, new DateTimeImmutable, new DateTimeImmutable);
    }

    public function test_a_visible_post_always_has_a_publication_time(): void
    {
        $this->expectException(InvalidPostException::class);

        new Post(PostId::generate(), DiscussionId::generate(), PostNumber::first(), null, $this->body(), PostStatus::Hidden, new DateTimeImmutable, null);
    }

    public function test_a_pending_post_never_has_a_number(): void
    {
        $this->expectException(InvalidPostException::class);

        new Post(PostId::generate(), DiscussionId::generate(), PostNumber::first(), null, $this->body(), PostStatus::Pending, new DateTimeImmutable, null);
    }

    public function test_the_author_is_a_lowercase_account_id(): void
    {
        $post = Post::publish(PostId::generate(), DiscussionId::generate(), PostNumber::first(), 'A1B2C3D4-0000-4000-8000-000000000001', $this->body(), new DateTimeImmutable);

        $this->assertSame('a1b2c3d4-0000-4000-8000-000000000001', $post->authorId());
    }

    public function test_the_author_is_a_valid_account_id(): void
    {
        $this->expectException(InvalidPostException::class);

        Post::publish(PostId::generate(), DiscussionId::generate(), PostNumber::first(), 'not-a-uuid', $this->body(), new DateTimeImmutable);
    }

    private function published(int $number): Post
    {
        return Post::publish(PostId::generate(), DiscussionId::generate(), new PostNumber($number), null, $this->body(), new DateTimeImmutable('2026-11-10 20:00:00'));
    }

    private function pending(): Post
    {
        return Post::submitForApproval(PostId::generate(), DiscussionId::generate(), null, $this->body(), new DateTimeImmutable('2026-11-10 20:00:00'));
    }

    private function body(): PostBody
    {
        return new PostBody(['type' => 'doc', 'content' => []], 'Hola');
    }
}
