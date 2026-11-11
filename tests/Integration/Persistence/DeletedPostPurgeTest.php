<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Forum\Application\Services\DeletedPostPurger;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Console\Commands\PurgeDeletedPostsCommand;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What an author deleted stays for moderators for 30 days and is then emptied, with its
 * versions and the images they used.
 */
final class DeletedPostPurgeTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string IMAGE = 'forum/posts/2026/10/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_a_post_deleted_more_than_30_days_ago_is_emptied_and_a_recent_one_is_kept(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory(), '2026-10-01 10:00:00');
        $old = $this->aReply($discussion, '2026-10-01 11:00:00');
        $this->writer()->editPost($old->id(), $this->withImage(), null, new DateTimeImmutable('2026-10-01 12:00:00'));
        $this->writer()->deletePostByAuthor($old->id(), new DateTimeImmutable('2026-10-10 09:00:00'));
        $recent = $this->aReply($discussion, '2026-10-01 13:00:00');
        $this->writer()->deletePostByAuthor($recent->id(), new DateTimeImmutable('2026-10-20 09:00:00'));
        $this->assertTrue($this->source()->usesFile($this->image()));

        $purged = app(DeletedPostPurger::class)->purge(new DateTimeImmutable('2026-11-12 04:10:00'));

        $this->assertSame(1, $purged);
        $emptied = $this->posts()->findOrFail($old->id());
        $this->assertSame(PostStatus::Deleted, $emptied->status());
        $this->assertSame(2, $emptied->number()?->value);
        $this->assertTrue($emptied->isContentPurged());
        $this->assertSame(['type' => 'doc', 'content' => []], $emptied->body()->document);
        $this->assertSame('', $emptied->body()->plainText);
        $this->assertSame([], app(PostRevisionRepositoryInterface::class)->ofPost($old->id()));
        $this->assertFalse($this->source()->usesFile($this->image()));

        $kept = $this->posts()->findOrFail($recent->id());
        $this->assertFalse($kept->isContentPurged());
        $this->assertSame('Respuesta', $kept->body()->plainText);

        // Nothing left to do on the next run
        $this->assertSame(0, app(DeletedPostPurger::class)->purge(new DateTimeImmutable('2026-11-12 04:10:00')));
    }

    public function test_the_purge_runs_every_day(): void
    {
        $events = array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'forum:purge-deleted-posts'),
        );

        $this->assertCount(1, $events);

        $command = app(PurgeDeletedPostsCommand::class);
        $command->setLaravel($this->app);
        $this->assertSame(0, $command->run(new ArrayInput([]), new BufferedOutput));
    }

    private function writer(): ForumWriter
    {
        return app(ForumWriter::class);
    }

    private function posts(): PostRepositoryInterface
    {
        return app(PostRepositoryInterface::class);
    }

    private function source(): PostImageMediaSource
    {
        return app(PostImageMediaSource::class);
    }

    private function image(): MediaFileId
    {
        $id = app(MediaFileIdResolverInterface::class)->fromStoredPath(self::IMAGE);
        $this->assertInstanceOf(MediaFileId::class, $id);

        return $id;
    }

    private function withImage(): PostBody
    {
        return new PostBody(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mapa']]],
            ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::IMAGE]],
        ]], 'Mapa');
    }
}
