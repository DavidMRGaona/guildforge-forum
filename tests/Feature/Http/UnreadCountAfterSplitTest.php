<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The unread count of a list row counts the posts that are still in the discussion after what
 * the reader read, not the numbers moderation moved to another discussion.
 */
final class UnreadCountAfterSplitTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $moderator;

    private UserModel $reader;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->moderator = $this->anAccount('forum:forum.moderate');
        $this->reader = $this->anAccount();
        [$this->discussion] = $this->aDiscussion($this->general, '2026-11-01 10:00:00', title: 'Larga');
        for ($index = 2; $index <= 26; $index++) {
            $this->aReply($this->discussion, sprintf('2026-11-01 10:%02d:00', $index), text: "Mensaje {$index}");
        }
    }

    public function test_numbers_that_moved_away_after_what_was_read_are_not_counted(): void
    {
        $this->readThrough(7);
        app(ForumModeration::class)->split($this->moderator, $this->discussion->id(), range(8, 11), 'Otro asunto', $this->general->id());

        $this->assertSame(15, $this->unreadCountOfRow());
    }

    public function test_a_discussion_without_gaps_counts_every_number_after_the_mark(): void
    {
        $this->readThrough(7);

        $this->assertSame(19, $this->unreadCountOfRow());
    }

    public function test_numbers_that_moved_before_the_mark_or_to_the_end_do_not_change_the_count(): void
    {
        $this->readThrough(15);
        app(ForumModeration::class)->split($this->moderator, $this->discussion->id(), [3, 4], 'Antes', $this->general->id());
        app(ForumModeration::class)->split($this->moderator, $this->discussion->id(), [25, 26], 'Después', $this->general->id());

        // 16 to 24 are left after the mark: 25 and 26 left, and so did 3 and 4 before it
        $this->assertSame(9, $this->unreadCountOfRow());
    }

    public function test_a_row_read_to_its_last_post_counts_nothing(): void
    {
        $this->readThrough(26);
        app(ForumModeration::class)->split($this->moderator, $this->discussion->id(), range(8, 11), 'Otro asunto', $this->general->id());

        $this->assertSame(0, $this->unreadCountOfRow());
    }

    public function test_a_row_never_opened_counts_what_is_left(): void
    {
        $this->readThrough(0);
        app(ForumModeration::class)->split($this->moderator, $this->discussion->id(), range(8, 11), 'Otro asunto', $this->general->id());

        $this->assertSame(22, $this->unreadCountOfRow());
    }

    private function readThrough(int $number): void
    {
        if ($number > 0) {
            app(DiscussionReadsInterface::class)->advance(strtolower($this->reader->id), $this->discussion->id(), $number, new DateTimeImmutable('2026-11-12 10:00:00'));
        }
    }

    private function unreadCountOfRow(): ?int
    {
        $count = null;
        $this->actingAs($this->reader)->get('/foro/c/general')->assertInertia(function (Assert $page) use (&$count): void {
            $page->where('discussions.items', static function (mixed $items) use (&$count): bool {
                foreach (is_iterable($items) ? $items : [] as $item) {
                    if (is_array($item) && ($item['number'] ?? null) === 1) {
                        $count = $item['unreadCount'] ?? null;
                    }
                }

                return true;
            });
        });

        return is_int($count) ? $count : null;
    }
}
