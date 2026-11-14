<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\PostReferenceRepositoryInterface;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Which posts point at which (by «#N» or by quoting them), one set of rows per post that
 * points, replaced whole each time it is written. Counts and lists only take visible posts.
 */
final class PostReferenceRepositoryTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Discussion $discussion;

    private Post $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        [$this->discussion, $this->target] = $this->aDiscussion($this->aCategory());
    }

    public function test_replacing_keeps_only_the_new_rows(): void
    {
        $source = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $other = $this->aReply($this->discussion, '2026-11-10 19:30:00');

        $this->references()->replaceFor($source->id(), [$this->target->id()->value, $other->id()->value], [$this->target->id()->value]);
        $this->references()->replaceFor($source->id(), [$other->id()->value], []);
        $this->references()->replaceFor($source->id(), [$other->id()->value], []);

        $this->assertSame([$other->id()->value], $this->references()->targetsOf($source->id()));
        $this->assertSame(1, DB::table('forum_post_references')->count());
    }

    public function test_a_post_pointing_at_itself_and_malformed_ids_are_left_out(): void
    {
        $source = $this->aReply($this->discussion, '2026-11-10 19:00:00');

        $this->references()->replaceFor($source->id(), [$source->id()->value, 'no-uuid', strtoupper($this->target->id()->value)], [$source->id()->value]);

        $this->assertSame([$this->target->id()->value], $this->references()->targetsOf($source->id()));
    }

    public function test_counts_group_by_the_discussion_of_each_visible_reply(): void
    {
        $reference = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $quote = $this->aReply($this->discussion, '2026-11-10 19:10:00');
        $hidden = $this->aReply($this->discussion, '2026-11-10 19:20:00');
        $pending = $this->aReply($this->discussion, '2026-11-10 19:30:00', needsApproval: true);
        $deleted = $this->aReply($this->discussion, '2026-11-10 19:40:00');
        foreach ([$reference, $hidden, $pending, $deleted] as $source) {
            $this->references()->replaceFor($source->id(), [$this->target->id()->value], []);
        }
        // Referenced and quoted by the same post: a reply counts once
        $this->references()->replaceFor($quote->id(), [$this->target->id()->value], [$this->target->id()->value]);
        app(ForumWriter::class)->changePost($hidden->id(), static fn (Post $post) => $post->hide());
        app(ForumWriter::class)->deletePostByAuthor($deleted->id(), new DateTimeImmutable('2026-11-10 20:00:00'));

        $this->assertSame(
            [$this->target->id()->value => [$this->discussion->id()->value => 2]],
            $this->references()->countsFor([$this->target->id()->value]),
        );
    }

    public function test_visible_sources_come_oldest_first(): void
    {
        $later = $this->aReply($this->discussion, '2026-11-10 20:00:00');
        $earlier = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $this->references()->replaceFor($later->id(), [$this->target->id()->value], []);
        $this->references()->replaceFor($earlier->id(), [], [$this->target->id()->value]);

        $this->assertSame(
            [
                ['postId' => $earlier->id()->value, 'discussionId' => $this->discussion->id()->value],
                ['postId' => $later->id()->value, 'discussionId' => $this->discussion->id()->value],
            ],
            $this->references()->visibleSourcesOf($this->target->id()),
        );
    }

    public function test_the_counts_of_many_posts_take_one_query(): void
    {
        $other = $this->aReply($this->discussion, '2026-11-10 19:00:00');

        DB::enableQueryLog();
        $this->references()->countsFor([$this->target->id()->value, $other->id()->value, 'no-uuid']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    private function references(): PostReferenceRepositoryInterface
    {
        return app(PostReferenceRepositoryInterface::class);
    }
}
