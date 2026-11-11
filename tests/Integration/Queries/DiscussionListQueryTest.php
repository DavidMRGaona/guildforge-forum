<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Queries;

use DateTimeImmutable;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Queries\DiscussionCursor;
use Modules\Forum\Application\Queries\DiscussionListCriteria;
use Modules\Forum\Application\Queries\DiscussionListQueryInterface;
use Modules\Forum\Application\Queries\DiscussionRowDTO;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Discussion lists: newest activity first, pinned ones apart on the first page, pages that
 * follow a cursor, hidden ones only where the reader moderates, and the reader's unread state.
 */
final class DiscussionListQueryTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->category = $this->aCategory();
    }

    public function test_discussions_come_by_last_activity_and_pages_follow_the_cursor(): void
    {
        [$old] = $this->aDiscussion($this->category, '2026-11-01 10:00:00', title: 'Antiguo');
        $this->aDiscussion($this->category, '2026-11-02 10:00:00', title: 'Medio');
        [$recent] = $this->aDiscussion($this->category, '2026-11-03 10:00:00', title: 'Reciente');
        // A reply brings the oldest one to the top
        $this->aReply($old, '2026-11-04 10:00:00');

        $first = $this->lists()->page($this->criteria(limit: 2));
        $this->assertSame(['Antiguo', 'Reciente'], $this->titles($first->items));
        $this->assertInstanceOf(DiscussionCursor::class, $first->next);

        $second = $this->lists()->page($this->criteria(limit: 2, after: DiscussionCursor::decode($first->next->encode())));
        $this->assertSame(['Medio'], $this->titles($second->items));
        $this->assertNull($second->next);

        $this->assertSame(2, $first->items[0]->postCount);
        $this->assertSame(2, $first->items[0]->lastPostNumber);
        $this->assertSame($recent->id()->value, $first->items[1]->id);
        $this->assertSame($recent->number(), $first->items[1]->number);
    }

    public function test_pinned_discussions_come_apart_on_the_first_page_only(): void
    {
        [$pinned] = $this->aDiscussion($this->category, '2026-11-01 10:00:00', title: 'Normas');
        $pinned->pin();
        app(ForumWriter::class)->saveDiscussion($pinned);
        $this->aDiscussion($this->category, '2026-11-02 10:00:00', title: 'Charla');

        $page = $this->lists()->page($this->criteria(pinnedFirst: true));
        $this->assertSame(['Normas'], $this->titles($page->pinned));
        $this->assertSame(['Charla'], $this->titles($page->items));

        $everything = $this->lists()->page($this->criteria());
        $this->assertSame([], $everything->pinned);
        $this->assertSame(['Charla', 'Normas'], $this->titles($everything->items));
    }

    public function test_hidden_discussions_show_only_in_moderated_categories(): void
    {
        [$hidden] = $this->aDiscussion($this->category, title: 'Oculto');
        $hidden->hide();
        app(ForumWriter::class)->saveDiscussion($hidden);

        $this->assertSame([], $this->lists()->page($this->criteria())->items);
        $this->assertSame(['Oculto'], $this->titles($this->lists()->page($this->criteria(moderated: true))->items));
    }

    public function test_only_the_categories_asked_for_are_listed(): void
    {
        $this->aDiscussion($this->aCategoryFor(CategoryAudience::signedIn(), 'privada'), title: 'Privado');
        $this->aDiscussion($this->category, title: 'Público');

        $this->assertSame(['Público'], $this->titles($this->lists()->page($this->criteria())->items));
        $this->assertSame([], $this->lists()->page(new DiscussionListCriteria([], [], 20))->items);
    }

    public function test_a_tag_filters_the_list_and_tags_in_use_are_offered(): void
    {
        [$tagged] = $this->aDiscussion($this->category, title: 'Con etiqueta');
        $this->aDiscussion($this->category, '2026-11-11 10:00:00', title: 'Sin etiqueta');
        $topic = new DiscussionTopic('game-system.0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f', 'Pathfinder');
        app(DiscussionRepositoryInterface::class)->replaceTopics($tagged->id(), [$topic, new DiscussionTopic('other.key', 'Otro')]);

        $page = $this->lists()->page($this->criteria(topic: $topic->key));
        $this->assertSame(['Con etiqueta'], $this->titles($page->items));
        $this->assertSame(['Otro', 'Pathfinder'], array_map(static fn (DiscussionTopic $t): string => $t->label, $page->items[0]->topics));

        // Only game-system tags are offered for filtering
        $this->assertSame(['Pathfinder'], array_map(static fn (DiscussionTopic $t): string => $t->label, $this->lists()->topicsIn([$this->category->id()->value])));
    }

    public function test_the_readers_unread_state_counts_its_own_mark_and_the_category_mark(): void
    {
        $reader = $this->anAccount();
        [$read] = $this->aDiscussion($this->category, '2026-11-01 10:00:00', title: 'Leído');
        [$partly] = $this->aDiscussion($this->category, '2026-11-02 10:00:00', title: 'A medias');
        $this->aReply($partly, '2026-11-02 11:00:00');
        $this->aDiscussion($this->category, '2026-11-03 10:00:00', title: 'Nuevo');

        $reads = app(DiscussionReadsInterface::class);
        $reads->advance($reader->id, $read->id(), 1, new DateTimeImmutable('2026-11-05'));
        $reads->advance($reader->id, $partly->id(), 1, new DateTimeImmutable('2026-11-05'));

        $rows = $this->byTitle($this->lists()->page($this->criteria(readerId: $reader->id))->items);
        $this->assertFalse($rows['Leído']->isUnread());
        $this->assertTrue($rows['A medias']->isUnread());
        $this->assertSame(1, $rows['A medias']->lastReadNumber);
        $this->assertTrue($rows['Nuevo']->isUnread());
        $this->assertSame(0, $rows['Nuevo']->lastReadNumber);

        $this->assertSame(['Nuevo', 'A medias'], $this->titles($this->lists()->page($this->criteria(readerId: $reader->id, onlyUnread: true))->items));

        // Marking the category read covers what came before; later activity is unread again
        $reads->markCategoriesRead($reader->id, [$this->category->id()], new DateTimeImmutable('2026-11-06 00:00:00'));
        $this->assertSame(0, count($this->lists()->page($this->criteria(readerId: $reader->id, onlyUnread: true))->items));

        $this->aReply($read, '2026-11-07 10:00:00');
        // A fresh criteria object: the same call as above now answers something else
        $unreadOnly = $this->criteria(readerId: $reader->id, onlyUnread: true);
        $unread = $this->lists()->page($unreadOnly)->items;
        $this->assertSame(['Leído'], $this->titles($unread));
        $this->assertSame(1, $unread[0]->lastReadNumber);

        // Without a reader there is no unread state
        $this->assertNull($this->lists()->page($this->criteria())->items[0]->lastReadNumber);
    }

    public function test_a_cursor_that_was_not_written_by_the_list_is_ignored(): void
    {
        $this->assertNull(DiscussionCursor::decode('not-a-cursor'));
        $this->assertNull(DiscussionCursor::decode(base64_encode('["x","y"]')));
        $cursor = new DiscussionCursor('2026-11-10 18:00:00', '0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f');
        $this->assertEquals($cursor, DiscussionCursor::decode($cursor->encode()));
    }

    private function criteria(
        int $limit = 20,
        ?DiscussionCursor $after = null,
        bool $pinnedFirst = false,
        bool $moderated = false,
        ?string $topic = null,
        ?string $readerId = null,
        bool $onlyUnread = false,
    ): DiscussionListCriteria {
        return new DiscussionListCriteria(
            categoryIds: [$this->category->id()->value],
            moderatedCategoryIds: $moderated ? [$this->category->id()->value] : [],
            limit: $limit,
            readerId: $readerId === null ? null : strtolower($readerId),
            onlyUnread: $onlyUnread,
            topicKey: $topic,
            pinnedFirst: $pinnedFirst,
            after: $after,
        );
    }

    private function lists(): DiscussionListQueryInterface
    {
        return app(DiscussionListQueryInterface::class);
    }

    /**
     * @param  list<DiscussionRowDTO>  $rows
     * @return list<string>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn (DiscussionRowDTO $row): string => $row->title, $rows);
    }

    /**
     * @param  list<DiscussionRowDTO>  $rows
     * @return array<string, DiscussionRowDTO>
     */
    private function byTitle(array $rows): array
    {
        return array_combine($this->titles($rows), $rows);
    }
}
