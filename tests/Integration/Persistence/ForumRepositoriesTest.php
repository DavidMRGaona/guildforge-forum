<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

final class ForumRepositoriesTest extends ModuleTestCase
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

    public function test_a_category_comes_back_as_it_was_saved(): void
    {
        $repository = app(CategoryRepositoryInterface::class);
        $category = new Category(CategoryId::generate(), 'Partidas por foro', new CategorySlug('partidas-por-foro'), 'Juego por turnos', 3);

        $repository->save($category);
        $found = $repository->findOrFail($category->id());

        $this->assertSame('Partidas por foro', $found->name());
        $this->assertSame('partidas-por-foro', $found->slug()->value);
        $this->assertSame('Juego por turnos', $found->description());
        $this->assertSame(3, $found->position());
        $this->assertTrue($repository->findBySlug(new CategorySlug('partidas-por-foro'))?->id()->equals($category->id()));
        $this->assertNull($repository->findBySlug(new CategorySlug('otra')));
    }

    public function test_saving_a_category_again_updates_it(): void
    {
        $repository = app(CategoryRepositoryInterface::class);
        $category = $this->aCategory('general');

        $category->rename('Charla', new CategorySlug('charla'), null);
        $category->moveToPosition(4);
        $repository->save($category);

        $found = $repository->findOrFail($category->id());
        $this->assertSame('charla', $found->slug()->value);
        $this->assertSame(4, $found->position());
        $this->assertSame(1, DB::table('forum_categories')->count());
    }

    public function test_slugs_are_unique(): void
    {
        $this->aCategory('general');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->aCategory('general');
    }

    public function test_missing_records_are_reported(): void
    {
        $this->assertNull(app(CategoryRepositoryInterface::class)->find(CategoryId::generate()));
        $this->assertNull(app(DiscussionRepositoryInterface::class)->find(DiscussionId::generate()));
        $this->assertNull(app(PostRepositoryInterface::class)->find(PostId::generate()));

        $this->expectException(CategoryNotFoundException::class);
        app(CategoryRepositoryInterface::class)->findOrFail(CategoryId::generate());
    }

    public function test_a_missing_discussion_or_post_throws_its_own_exception(): void
    {
        try {
            app(DiscussionRepositoryInterface::class)->findOrFail(DiscussionId::generate());
            $this->fail('No exception for a missing discussion.');
        } catch (DiscussionNotFoundException) {
        }

        $this->expectException(PostNotFoundException::class);
        app(PostRepositoryInterface::class)->findOrFail(PostId::generate());
    }

    public function test_a_discussion_comes_back_with_its_state_and_author(): void
    {
        $author = UserModel::factory()->create();
        $category = $this->aCategory();
        $discussion = new Discussion(DiscussionId::generate(), $category->id(), $author->id, new DiscussionTitle('Reglas'), DiscussionStatus::Closed, pinned: true, hidden: true, createdAt: new DateTimeImmutable('2026-11-10 18:30:00'));

        app(DiscussionRepositoryInterface::class)->save($discussion);
        $found = app(DiscussionRepositoryInterface::class)->findOrFail($discussion->id());

        $this->assertSame('Reglas', $found->title()->value);
        $this->assertSame(DiscussionStatus::Closed, $found->status());
        $this->assertTrue($found->isPinned());
        $this->assertTrue($found->isHidden());
        $this->assertSame($author->id, $found->authorId());
        $this->assertTrue($found->categoryId()->equals($category->id()));
        $this->assertSame('2026-11-10 18:30:00', $found->createdAt()->format('Y-m-d H:i:s'));
    }

    public function test_a_post_comes_back_with_its_document_text_number_and_status(): void
    {
        [$discussion, $first] = $this->aDiscussion($this->aCategory());
        $found = app(PostRepositoryInterface::class)->findOrFail($first->id());

        $this->assertSame(1, $found->number()?->value);
        $this->assertSame(PostStatus::Visible, $found->status());
        $this->assertSame('Primer mensaje', $found->body()->plainText);
        $this->assertSame($first->body()->document, $found->body()->document);
        $this->assertTrue($found->discussionId()->equals($discussion->id()));
        $this->assertSame('2026-11-10 18:00:00', $found->createdAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 18:00:00', $found->publishedAt()?->format('Y-m-d H:i:s'));
        $this->assertNull($found->editedAt());
    }

    public function test_an_edit_and_a_status_change_are_saved(): void
    {
        [, $post] = $this->aDiscussion($this->aCategory());

        $post->edit(self::aBody('Corregido'), new DateTimeImmutable('2026-11-10 19:00:00'), null);
        $post->hide();
        app(PostRepositoryInterface::class)->save($post);

        $found = app(PostRepositoryInterface::class)->findOrFail($post->id());
        $this->assertSame('Corregido', $found->body()->plainText);
        $this->assertSame(PostStatus::Hidden, $found->status());
        $this->assertSame('2026-11-10 19:00:00', $found->editedAt()?->format('Y-m-d H:i:s'));
    }

    public function test_the_highest_number_counts_every_numbered_post(): void
    {
        [$discussion, $first] = $this->aDiscussion($this->aCategory());
        $posts = app(PostRepositoryInterface::class);

        $this->assertSame(1, $posts->highestNumber($discussion->id())?->value);

        $first->hide();
        $posts->save($first);
        $this->aReply($discussion, '2026-11-10 18:05:00', needsApproval: true);

        $this->assertSame(1, $posts->highestNumber($discussion->id())?->value);
        $this->assertNull($posts->highestNumber(DiscussionId::generate()));
    }

    public function test_the_posts_of_a_discussion_come_in_their_order_with_pending_ones_last(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $pending = $this->aReply($discussion, '2026-11-10 18:01:00', needsApproval: true);
        $this->aReply($discussion, '2026-11-10 18:02:00', text: 'Segunda');

        $posts = app(PostRepositoryInterface::class)->inDiscussion($discussion->id());

        $this->assertSame([1, 2, null], array_map(fn (Post $post): ?int => $post->number()?->value, $posts));
        $this->assertTrue($posts[2]->id()->equals($pending->id()));
    }

    public function test_two_posts_never_share_a_number(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());

        $this->expectException(UniqueConstraintViolationException::class);

        app(PostRepositoryInterface::class)->save(Post::publish(PostId::generate(), $discussion->id(), PostNumber::first(), null, self::aBody('Copia'), new DateTimeImmutable));
    }

    public function test_deleting_an_account_keeps_what_it_wrote_without_an_author(): void
    {
        $author = UserModel::factory()->create();
        [$discussion, $post] = $this->aDiscussion($this->aCategory(), authorId: $author->id);

        $author->forceDelete();

        $this->assertNull(app(DiscussionRepositoryInterface::class)->findOrFail($discussion->id())->authorId());
        $this->assertNull(app(PostRepositoryInterface::class)->findOrFail($post->id())->authorId());
    }

    public function test_a_category_with_discussions_is_never_deleted(): void
    {
        $category = $this->aCategory();
        $this->aDiscussion($category);

        $this->expectException(QueryException::class);

        app(CategoryRepositoryInterface::class)->delete($category->id());
    }

    public function test_deleting_a_discussion_takes_its_posts_with_it(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $this->aReply($discussion, '2026-11-10 18:01:00');

        app(DiscussionRepositoryInterface::class)->delete($discussion->id());

        $this->assertSame(0, DB::table('forum_posts')->count());
    }
}
