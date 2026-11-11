<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Editing keeps every replaced version for moderators; deleting by the author keeps the post's
 * place in the numbering.
 */
final class PostEditsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_each_edit_keeps_what_it_replaced_as_the_next_revision(): void
    {
        $editor = UserModel::factory()->create()->id;
        [, $post] = $this->aDiscussion($this->aCategory());

        $this->writer()->editPost($post->id(), self::aBody('Segunda versión'), $editor, new DateTimeImmutable('2026-11-10 19:00:00'));
        $edited = $this->writer()->editPost($post->id(), self::aBody('Tercera versión'), null, new DateTimeImmutable('2026-11-10 20:00:00'));

        $this->assertSame('Tercera versión', $edited->body()->plainText);
        $this->assertSame('2026-11-10 20:00:00', $edited->editedAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('Tercera versión', app(PostRepositoryInterface::class)->findOrFail($post->id())->body()->plainText);

        $revisions = app(PostRevisionRepositoryInterface::class)->ofPost($post->id());
        $this->assertSame([1, 2], array_map(static fn ($revision): int => $revision->number, $revisions));
        $this->assertSame(['Primer mensaje', 'Segunda versión'], array_map(static fn ($revision): string => $revision->body->plainText, $revisions));
        $this->assertSame([strtolower((string) $editor), null], array_map(static fn ($revision): ?string => $revision->editorId(), $revisions));
        $this->assertSame('2026-11-10 19:00:00', $revisions[0]->replacedAt->format('Y-m-d H:i:s'));
    }

    public function test_an_edit_that_changes_nothing_keeps_no_revision(): void
    {
        [, $post] = $this->aDiscussion($this->aCategory());

        $same = $this->writer()->editPost($post->id(), self::aBody('Primer mensaje'), null, new DateTimeImmutable('2026-11-10 19:00:00'));

        $this->assertNull($same->editedAt());
        $this->assertSame([], app(PostRevisionRepositoryInterface::class)->ofPost($post->id()));
    }

    public function test_deleting_by_the_author_keeps_the_number_and_the_versions_but_not_the_count(): void
    {
        $category = $this->aCategory();
        [$discussion, $first] = $this->aDiscussion($category);
        $reply = $this->aReply($discussion, '2026-11-10 18:30:00');
        $this->writer()->editPost($reply->id(), self::aBody('Corregida'), null, new DateTimeImmutable('2026-11-10 18:40:00'));

        $deleted = $this->writer()->deletePostByAuthor($reply->id());

        $this->assertSame(PostStatus::Deleted, $deleted->status());
        $this->assertSame(2, $deleted->number()?->value);
        $this->assertSame('Corregida', app(PostRepositoryInterface::class)->findOrFail($reply->id())->body()->plainText);
        $this->assertCount(1, app(PostRevisionRepositoryInterface::class)->ofPost($reply->id()));

        $counters = app(ForumCountersInterface::class)->discussion($discussion->id());
        $this->assertSame(1, $counters?->postCount);
        $this->assertTrue($counters->lastPostId?->equals($first->id()));
    }

    public function test_a_post_its_author_deleted_cannot_be_edited_or_deleted_again(): void
    {
        [, $post] = $this->aDiscussion($this->aCategory());
        $this->writer()->deletePostByAuthor($post->id());

        foreach ([
            fn (): mixed => $this->writer()->editPost($post->id(), self::aBody('Otra cosa'), null, new DateTimeImmutable('2026-11-10 19:00:00')),
            fn (): mixed => $this->writer()->deletePostByAuthor($post->id()),
        ] as $write) {
            try {
                $write();
                $this->fail('The write went through.');
            } catch (InvalidPostException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], app(PostRevisionRepositoryInterface::class)->ofPost($post->id()));
    }

    private function writer(): ForumWriter
    {
        return app(ForumWriter::class);
    }
}
