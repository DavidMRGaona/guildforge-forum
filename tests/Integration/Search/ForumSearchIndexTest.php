<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Search;

use App\Application\Modules\Services\EnabledModulesResolverInterface;
use App\Application\Search\DTOs\SearchDocumentDTO;
use App\Application\Search\Services\SearchIndexerInterface;
use App\Application\Search\Services\SearchSourceRegistryInterface;
use App\Domain\Search\Enums\SearchAudience;
use App\Infrastructure\Search\Index\NullSearchIndexWriter;
use App\Infrastructure\Search\Index\SearchIndexWriterInterface;
use App\Infrastructure\Search\Persistence\Eloquent\Models\SearchDocumentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Infrastructure\Search\DiscussionSearchSource;
use Modules\Forum\Infrastructure\Search\PostSearchSource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the forum offers the site search: discussions (title and opening message) and replies,
 * each with the audience its category allows. What the search audiences cannot express (roles,
 * a campaign's roster), what is hidden and what awaits approval is never indexed, and every
 * write refreshes the index.
 */
final class ForumSearchIndexTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private const string ROLE = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        // Only the stored documents, never a real engine
        $this->app->instance(SearchIndexWriterInterface::class, new NullSearchIndexWriter);
    }

    public function test_both_types_are_registered_with_the_search(): void
    {
        $types = array_map(static fn ($source): string => $source->type(), app(SearchSourceRegistryInterface::class)->indexedSources());

        $this->assertContains('forum-discussion', $types);
        $this->assertContains('forum-post', $types);
    }

    public function test_a_discussion_document_holds_its_title_opening_message_and_address(): void
    {
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        [$discussion] = $this->aDiscussion($category, title: 'Mesa del viernes');

        $document = $this->discussions()->document($discussion->id()->value);

        $this->assertNotNull($document);
        $this->assertSame($discussion->id()->value, $document->id);
        $this->assertSame('Mesa del viernes', $document->title);
        $this->assertSame('Primer mensaje', $document->body);
        $this->assertSame('/foro/t/1-mesa-del-viernes', $document->url);
        $this->assertSame('General', $document->meta);
        $this->assertSame(SearchAudience::Public, $document->audience);
        $this->assertNotNull($document->publishedAt);
    }

    public function test_replies_are_post_documents_at_their_own_address_and_the_opening_message_is_not(): void
    {
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        [$discussion, $opening] = $this->aDiscussion($category, title: 'Mesa del viernes');
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Llevo los dados');

        $this->assertNull($this->posts()->document($opening->id()->value));

        $document = $this->posts()->document($reply->id()->value);
        $this->assertNotNull($document);
        $this->assertSame('Mesa del viernes', $document->title);
        $this->assertSame('Llevo los dados', $document->body);
        $this->assertSame('/foro/t/1-mesa-del-viernes/2', $document->url);
        $this->assertSame([$reply->id()->value], $this->ids($this->posts()->documents()));
        $this->assertSame([$discussion->id()->value], $this->ids($this->discussions()->documents()));
    }

    /**
     * @return array<string, array{string, ?SearchAudience}>
     */
    public static function audiences(): array
    {
        return [
            'everyone' => ['everyone', SearchAudience::Public],
            'signed in' => ['signed_in', SearchAudience::Authenticated],
            'association members' => ['association_members', SearchAudience::Members],
            'roles' => ['roles', null],
            'roster' => ['roster', null],
            'roster leaders' => ['roster_leaders', null],
        ];
    }

    #[DataProvider('audiences')]
    public function test_a_category_gives_its_documents_the_audience_of_its_read_rule_or_none(string $kind, ?SearchAudience $expected): void
    {
        $view = $this->audience($kind);
        $category = new Category(CategoryId::generate(), 'Zona', new CategorySlug('zona'), null, 0, viewAudience: $view, subject: $view->needsSubject() ? new CategorySubject('campaign', '0b6e1d7e-5c8f-4e0e-9b39-3b9a5a1f7a11') : null);
        app(CategoryRepositoryInterface::class)->save($category);
        [$discussion] = $this->aDiscussion($category);
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00');

        $document = $this->discussions()->document($discussion->id()->value);
        $post = $this->posts()->document($reply->id()->value);

        if (! $expected instanceof SearchAudience) {
            $this->assertNull($document);
            $this->assertNull($post);

            return;
        }

        $this->assertSame($expected, $document?->audience);
        $this->assertSame($expected, $post?->audience);
    }

    public function test_a_subcategory_takes_the_narrower_of_its_rule_and_its_parents(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        $signedIn = $this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas');
        $roles = $this->aCategoryFor(CategoryAudience::roles([self::ROLE]), 'directiva');

        [$open] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'abierta', parent: $members));
        [$both] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::associationMembers(), 'mas-socios', parent: $signedIn));
        [$underRoles] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'bajo-roles', parent: $roles));
        [$overRoles] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::associationMembers(), 'socios-bajo-roles', parent: $roles));

        $this->assertSame(SearchAudience::Members, $this->discussions()->document($open->id()->value)?->audience);
        $this->assertSame(SearchAudience::Members, $this->discussions()->document($both->id()->value)?->audience);
        // Reading needs both rules: what the audiences cannot say stays out, however wide the other rule is
        $this->assertNull($this->discussions()->document($underRoles->id()->value));
        $this->assertNull($this->discussions()->document($overRoles->id()->value));
    }

    public function test_hidden_pending_and_deleted_content_is_never_a_document(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        [$discussion] = $this->aDiscussion($category, title: 'Visible');
        [$hiddenDiscussion, $hiddenOpening] = $this->aDiscussion($category, title: 'Oculto');
        [$awaiting] = $this->aDiscussion($category, needsApproval: true, title: 'Pendiente');
        $visible = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Se ve');
        $hiddenReply = $this->aReply($discussion, '2026-11-10 18:11:00', text: 'Oculta');
        $pending = $this->aReply($discussion, '2026-11-10 18:12:00', needsApproval: true, text: 'Pendiente');
        $deleted = $this->aReply($discussion, '2026-11-10 18:13:00', text: 'Borrada');
        $reply = $this->aReply($hiddenDiscussion, '2026-11-10 18:14:00', text: 'En tema oculto');

        app(ForumModeration::class)->hidePost($moderator, $hiddenReply->id());
        app(ForumWriter::class)->deletePostByAuthor($deleted->id(), new \DateTimeImmutable('2026-11-10 19:00:00'));
        app(ForumModeration::class)->hide($moderator, $hiddenDiscussion->id());

        $this->assertSame([$discussion->id()->value], $this->ids($this->discussions()->documents()));
        $this->assertNull($this->discussions()->document($hiddenDiscussion->id()->value));
        $this->assertNull($this->discussions()->document($awaiting->id()->value));
        $this->assertSame([$visible->id()->value], $this->ids($this->posts()->documents()));
        foreach ([$hiddenReply, $pending, $deleted, $reply, $hiddenOpening] as $post) {
            $this->assertNull($this->posts()->document($post->id()->value), $post->id()->value);
        }
    }

    public function test_ids_that_are_not_uuids_are_no_documents(): void
    {
        $this->assertNull($this->discussions()->document("1' or '1'='1"));
        $this->assertNull($this->posts()->document('no-es-un-uuid'));
    }

    public function test_publishing_a_discussion_and_replying_write_documents_with_the_audience(): void
    {
        $category = $this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas');
        [$discussion] = $this->aDiscussion($category, title: 'Tema nuevo');
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Respuesta nueva');

        $this->assertSame('authenticated', $this->row('forum-discussion', $discussion->id()->value)?->audience);
        $this->assertSame('Primer mensaje', $this->row('forum-discussion', $discussion->id()->value)->body);
        $this->assertSame('authenticated', $this->row('forum-post', $reply->id()->value)?->audience);
        $this->assertSame('forum', $this->row('forum-post', $reply->id()->value)->module);
    }

    public function test_editing_updates_the_document_and_renaming_updates_the_replies_and_the_address(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        [$discussion, $opening] = $this->aDiscussion($category, title: 'Título viejo');
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Antes');

        app(ForumWriter::class)->editPost($reply->id(), self::aBody('Después'), null, new \DateTimeImmutable('2026-11-10 19:00:00'));
        app(ForumWriter::class)->editPost($opening->id(), self::aBody('Apertura editada'), null, new \DateTimeImmutable('2026-11-10 19:01:00'));
        app(ForumModeration::class)->rename($moderator, $discussion->id(), 'Título nuevo');

        $this->assertSame('Después', $this->row('forum-post', $reply->id()->value)?->body);
        $this->assertSame('Título nuevo', $this->row('forum-post', $reply->id()->value)->title);
        $this->assertSame('/foro/t/1-titulo-nuevo/2', $this->row('forum-post', $reply->id()->value)?->url);
        $this->assertSame('Apertura editada', $this->row('forum-discussion', $discussion->id()->value)?->body);
        $this->assertSame('/foro/t/1-titulo-nuevo', $this->row('forum-discussion', $discussion->id()->value)->url);
    }

    public function test_hiding_and_showing_a_post_removes_and_restores_its_document(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'general'));
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00');

        app(ForumModeration::class)->hidePost($moderator, $reply->id());
        $this->assertNull($this->row('forum-post', $reply->id()->value));

        app(ForumModeration::class)->showPost($moderator, $reply->id());
        $this->assertNotNull($this->row('forum-post', $reply->id()->value));
    }

    public function test_hiding_a_discussion_takes_it_and_its_replies_out_and_showing_it_brings_them_back(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'general'));
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00');

        app(ForumModeration::class)->hide($moderator, $discussion->id());
        $this->assertNull($this->row('forum-discussion', $discussion->id()->value));
        $this->assertNull($this->row('forum-post', $reply->id()->value));

        app(ForumModeration::class)->show($moderator, $discussion->id());
        $this->assertNotNull($this->row('forum-discussion', $discussion->id()->value));
        $this->assertNotNull($this->row('forum-post', $reply->id()->value));
    }

    public function test_approving_a_reply_or_a_first_post_publishes_its_document(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        [$awaiting, $opening] = $this->aDiscussion($category, needsApproval: true);
        [$discussion] = $this->aDiscussion($category, title: 'Otro tema');
        $pending = $this->aReply($discussion, '2026-11-10 18:10:00', needsApproval: true, text: 'A la espera');

        $this->assertNull($this->row('forum-discussion', $awaiting->id()->value));
        $this->assertNull($this->row('forum-post', $pending->id()->value));

        app(ForumModeration::class)->approve($moderator, $opening->id());
        app(ForumModeration::class)->approve($moderator, $pending->id());

        $this->assertSame('Primer mensaje', $this->row('forum-discussion', $awaiting->id()->value)?->body);
        $this->assertNotNull($this->row('forum-post', $pending->id()->value));
    }

    public function test_moving_a_discussion_gives_it_and_its_replies_the_audience_of_the_new_category(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        $open = $this->aCategoryFor(CategoryAudience::everyone(), 'abierta');
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios', position: 1);
        $roles = $this->aCategoryFor(CategoryAudience::roles([self::ROLE]), 'directiva', position: 2);
        [$discussion] = $this->aDiscussion($open);
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00');

        app(ForumModeration::class)->move($moderator, $discussion->id(), $members->id());
        $this->assertSame('members', $this->row('forum-discussion', $discussion->id()->value)?->audience);
        $this->assertSame('members', $this->row('forum-post', $reply->id()->value)?->audience);

        app(ForumModeration::class)->move($moderator, $discussion->id(), $roles->id());
        $this->assertNull($this->row('forum-discussion', $discussion->id()->value));
        $this->assertNull($this->row('forum-post', $reply->id()->value));

        app(ForumModeration::class)->move($moderator, $discussion->id(), $open->id());
        $this->assertSame('public', $this->row('forum-discussion', $discussion->id()->value)?->audience);
        $this->assertSame('public', $this->row('forum-post', $reply->id()->value)?->audience);
    }

    public function test_changing_who_reads_a_category_updates_its_documents_and_its_subcategories(): void
    {
        $parent = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $child = $this->aCategoryFor(CategoryAudience::everyone(), 'sub', parent: $parent);
        [$inParent] = $this->aDiscussion($parent);
        [$inChild] = $this->aDiscussion($child);
        $reply = $this->aReply($inChild, '2026-11-10 18:10:00');

        $parent->changeAccess(CategoryAudience::associationMembers(), $parent->writeAudience(), $parent->moderators());
        app(CategoryRepositoryInterface::class)->save($parent);

        $this->assertSame('members', $this->row('forum-discussion', $inParent->id()->value)?->audience);
        $this->assertSame('members', $this->row('forum-discussion', $inChild->id()->value)?->audience);
        $this->assertSame('members', $this->row('forum-post', $reply->id()->value)?->audience);

        $parent->changeAccess(CategoryAudience::roles([self::ROLE]), $parent->writeAudience(), $parent->moderators());
        app(CategoryRepositoryInterface::class)->save($parent);

        $this->assertNull($this->row('forum-discussion', $inParent->id()->value));
        $this->assertNull($this->row('forum-discussion', $inChild->id()->value));
        $this->assertNull($this->row('forum-post', $reply->id()->value));
    }

    public function test_rejecting_deleting_and_moving_a_whole_category_away_keeps_the_index_in_line(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $other = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios', position: 1);
        [$awaiting, $opening] = $this->aDiscussion($category, needsApproval: true);
        [$gone] = $this->aDiscussion($category, title: 'Se borra');
        $goneReply = $this->aReply($gone, '2026-11-10 18:10:00');
        [$stays] = $this->aDiscussion($category, title: 'Se muda');
        $staysReply = $this->aReply($stays, '2026-11-10 18:11:00');

        app(ForumModeration::class)->reject($moderator, $opening->id());
        app(ForumModeration::class)->delete($moderator, $gone->id());
        $this->assertNull($this->row('forum-discussion', $awaiting->id()->value));
        $this->assertNull($this->row('forum-discussion', $gone->id()->value));
        $this->assertNull($this->row('forum-post', $goneReply->id()->value));

        app(CategoryManager::class)->delete($category->id(), $other->id());
        $this->assertSame('members', $this->row('forum-discussion', $stays->id()->value)?->audience);
        $this->assertSame('members', $this->row('forum-post', $staysReply->id()->value)?->audience);
    }

    public function test_the_core_reconciliation_rebuilds_the_forum_types_and_drops_stale_rows(): void
    {
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        [$discussion] = $this->aDiscussion($category);
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00');
        // A stale row for something that no longer exists, and a missing one
        SearchDocumentModel::query()->create([
            'type' => 'forum-post', 'source_id' => (string) Str::uuid(), 'module' => 'forum', 'title' => 'Fantasma',
            'body' => 'x', 'url' => '/foro/t/99', 'audience' => 'public', 'locale' => 'es',
        ]);
        SearchDocumentModel::query()->where('source_id', $reply->id()->value)->delete();

        $result = app(SearchIndexerInterface::class)->sync();

        $this->assertNull($result->error);
        $this->assertSame(['forum-discussion' => 1, 'forum-post' => 1], $this->counts());
    }

    public function test_disabling_or_uninstalling_the_module_empties_its_rows_from_the_index(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'general'));
        $this->aReply($discussion, '2026-11-10 18:10:00');
        $this->assertSame(['forum-discussion' => 1, 'forum-post' => 1], $this->counts());

        $enabled = new class implements EnabledModulesResolverInterface
        {
            public function names(): array
            {
                return [];
            }

            public function modules(): array
            {
                return [];
            }

            public function rejected(): array
            {
                return [];
            }

            public function migrationPaths(): array
            {
                return [];
            }

            public function reset(): void {}
        };
        $this->app->instance(EnabledModulesResolverInterface::class, $enabled);
        $this->app->forgetInstance(SearchIndexerInterface::class);

        app(SearchIndexerInterface::class)->sync();

        $this->assertSame([], $this->counts());
    }

    public function test_a_failing_index_never_breaks_a_forum_write(): void
    {
        $this->app->bind(SearchIndexerInterface::class, static fn () => throw new \RuntimeException('index down'));
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');

        [$discussion] = $this->aDiscussion($category);
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00');

        $this->assertSame(2, DB::table('forum_posts')->count());
        $this->assertNotNull($reply->number());
    }

    private function discussions(): DiscussionSearchSource
    {
        return app(DiscussionSearchSource::class);
    }

    private function posts(): PostSearchSource
    {
        return app(PostSearchSource::class);
    }

    private function audience(string $kind): CategoryAudience
    {
        return match ($kind) {
            'everyone' => CategoryAudience::everyone(),
            'signed_in' => CategoryAudience::signedIn(),
            'association_members' => CategoryAudience::associationMembers(),
            'roles' => CategoryAudience::roles([self::ROLE]),
            'roster' => CategoryAudience::roster(),
            default => CategoryAudience::rosterLeaders(),
        };
    }

    /**
     * @param  iterable<SearchDocumentDTO>  $documents
     * @return list<string>
     */
    private function ids(iterable $documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            $ids[] = $document->id;
        }

        return $ids;
    }

    private function row(string $type, string $id): ?SearchDocumentModel
    {
        return SearchDocumentModel::query()->where('type', $type)->where('source_id', $id)->first();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        /** @var array<string, int> $counts */
        $counts = SearchDocumentModel::query()->toBase()->select('type')->selectRaw('COUNT(*) as total')->groupBy('type')->orderBy('type')->pluck('total', 'type')->map(static fn ($total): int => (int) $total)->all();

        return $counts;
    }
}
