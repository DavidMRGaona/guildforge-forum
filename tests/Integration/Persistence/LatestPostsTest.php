<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The last numbered posts of a discussion inside a range, for a conversation read from its end
 * (a record's page): in order, whatever their status, and never those awaiting approval.
 */
final class LatestPostsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_it_reads_the_last_numbered_posts_of_a_range_in_order(): void
    {
        $this->useUtcStorage();
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'general'), '2026-11-10 10:00:00');
        for ($i = 2; $i <= 9; $i++) {
            $this->aReply($discussion, sprintf('2026-11-10 10:%02d:00', $i));
        }
        $this->aReply($discussion, '2026-11-10 11:00:00', needsApproval: true);
        $posts = app(PostRepositoryInterface::class);

        $numbers = static fn (array $found): array => array_map(static fn ($post): ?int => $post->number()?->value, $found);

        $this->assertSame([6, 7, 8, 9], $numbers($posts->lastNumbered($discussion->id(), new PostNumber(2), null, 4)));
        $this->assertSame([3, 4, 5], $numbers($posts->lastNumbered($discussion->id(), new PostNumber(2), new PostNumber(6), 3)));
        $this->assertSame([2, 3], $numbers($posts->lastNumbered($discussion->id(), new PostNumber(2), new PostNumber(4), 10)));
        $this->assertSame([], $posts->lastNumbered($discussion->id(), new PostNumber(2), new PostNumber(2), 10));
    }
}
