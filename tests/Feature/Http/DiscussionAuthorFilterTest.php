<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Services\UserServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * `?autor=<id>` on a discussion: only that person's messages, paged like the discussion, with
 * the same read rule, the same hiding of shared characters and nothing said about who does not
 * exist.
 */
final class DiscussionAuthorFilterTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $category;

    private Discussion $discussion;

    private UserModel $ana;

    private UserModel $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['modules.settings.forum.posts_per_page' => 10]);
        $this->category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->ana = UserModel::factory()->create(['name' => 'ana.login', 'display_name' => 'Ana']);
        $this->luis = UserModel::factory()->create(['name' => 'luis.login', 'display_name' => 'Luis']);
        // Ana wrote the odd numbers of 1 to 25 (the opening one too), Luis the even ones of 2 to 24
        [$this->discussion] = $this->aDiscussion($this->category, authorId: $this->ana->id);
        for ($number = 2; $number <= 25; $number++) {
            $this->postAs($number % 2 === 0 ? $this->luis : $this->ana, "Mensaje {$number}");
        }
    }

    public function test_it_lists_only_the_messages_of_that_person_paged_like_the_discussion(): void
    {
        $this->get($this->url($this->ana->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts', fn ($posts): bool => $posts->pluck('number')->all() === [1, 3, 5, 7, 9, 11, 13, 15, 17, 19])
                ->where('filter.authorName', 'Ana')
                ->where('filter.count', 13)
                ->where('filter.clearUrl', $this->discussionUrl())
                ->where('pagination.pageCount', 2)
                ->where('pagination.page', 1)
                ->where('pagination.previousUrl', null)
                ->where('pagination.nextUrl', $this->url($this->ana->id).'&pagina=2')
                ->where('readUrl', null)
                ->where('focusNumber', null)
                ->etc());

        $this->get($this->url($this->ana->id).'&pagina=2')
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts', fn ($posts): bool => $posts->pluck('number')->all() === [21, 23, 25])
                ->where('pagination.page', 2)
                ->where('pagination.nextUrl', null)
                ->where('pagination.previousUrl', $this->url($this->ana->id))
                ->etc());
    }

    public function test_the_permanent_links_of_the_messages_still_lead_to_the_whole_discussion(): void
    {
        $this->get($this->url($this->ana->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.url', $this->discussionUrl())
                ->where('posts.1.url', $this->discussionUrl().'/3')
                ->etc());
    }

    public function test_the_filtered_page_is_not_indexed_and_points_at_the_whole_discussion(): void
    {
        $this->get($this->url($this->ana->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('seo.robots', fn (?string $robots): bool => str_contains((string) $robots, 'noindex'))
                ->where('seo.canonicalUrl', url($this->discussionUrl()))
                ->etc());
    }

    public function test_a_post_number_in_the_address_ignores_the_filter(): void
    {
        $this->get($this->discussionUrl().'/3?autor='.$this->ana->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filter', null)->etc());
    }

    public function test_an_unknown_or_malformed_author_looks_like_a_person_without_messages(): void
    {
        $nobody = UserModel::factory()->create(['name' => 'Sin mensajes']);
        $expected = fn (Assert $page) => $page
            ->where('posts', [])
            ->where('filter.authorName', null)
            ->where('filter.count', 0)
            ->where('pagination.pageCount', 1)
            ->etc();

        foreach ([$nobody->id, '3f1c5a0e-1d2b-4c3d-8e9f-a0b1c2d3e4f5', 'no-es-un-id', str_repeat('a', 300)] as $author) {
            $this->get($this->url($author))->assertOk()->assertInertia($expected);
        }
    }

    public function test_a_repeated_or_array_filter_is_not_a_filter(): void
    {
        $this->get($this->discussionUrl().'?autor[]='.$this->ana->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filter.count', 0)->where('posts', [])->etc());
    }

    public function test_a_page_past_the_end_shows_the_last_one(): void
    {
        $this->get($this->url($this->ana->id).'&pagina=99')
            ->assertInertia(fn (Assert $page) => $page->where('pagination.page', 2)->etc());
    }

    public function test_it_follows_the_read_rule_of_the_discussion(): void
    {
        $private = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        [$closed] = $this->aDiscussion($private, authorId: $this->ana->id, title: 'Privado');

        $this->get($this->discussionUrl($closed).'?autor='.$this->ana->id)->assertNotFound();
        $this->actingAs($this->luis)->get($this->discussionUrl($closed).'?autor='.$this->ana->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filter.count', 1)->etc());
    }

    public function test_messages_of_a_shared_character_do_not_show_nor_count_for_a_reader_who_may_not_know_the_writer(): void
    {
        $shared = $this->aSharedCharacterIn($this->category);
        $this->postAs($this->luis, 'Habla el mercader', $shared);

        // Luis wrote the even numbers up to 24 and the shared 26: a stranger only sees the former
        $this->get($this->url($this->luis->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.count', 12)
                ->where('posts', fn ($posts): bool => $posts->pluck('number')->all() === [2, 4, 6, 8, 10, 12, 14, 16, 18, 20])
                ->etc());
        // The writer reads their own
        $this->actingAs($this->luis)->get($this->url($this->luis->id))
            ->assertInertia(fn (Assert $page) => $page->where('filter.count', 13)->etc());
        // So does moderation
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        $this->actingAs($moderator)->get($this->url($this->luis->id))
            ->assertInertia(fn (Assert $page) => $page->where('filter.count', 13)->etc());
    }

    public function test_someone_who_only_wrote_as_a_shared_character_is_a_person_without_messages(): void
    {
        $shared = $this->aSharedCharacterIn($this->category);
        $director = UserModel::factory()->create(['name' => 'Dirección']);
        $this->postAs($director, 'Habla el mercader', $shared);

        $this->get($this->url($director->id))
            ->assertInertia(fn (Assert $page) => $page->where('posts', [])->where('filter.authorName', null)->where('filter.count', 0)->etc());
    }

    public function test_without_the_filter_the_page_carries_none(): void
    {
        $this->get($this->discussionUrl())
            ->assertInertia(fn (Assert $page) => $page->where('filter', null)->etc());
    }

    public function test_the_notice_names_the_public_name_not_the_login(): void
    {
        $this->get($this->url($this->ana->id))
            ->assertInertia(fn (Assert $page) => $page->where('filter.authorName', 'Ana')->etc());
    }

    public function test_an_empty_or_upper_case_author_is_handled(): void
    {
        $this->get($this->discussionUrl().'?autor=')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('posts', [])->where('filter.count', 0)->etc());

        $upper = strtoupper($this->ana->id);
        $this->get($this->url($upper))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.count', 13)
                ->where('pagination.nextUrl', $this->url(strtolower($this->ana->id)).'&pagina=2')
                ->etc());
    }

    public function test_an_anonymized_account_keeps_its_messages_under_the_anonymous_name_as_in_the_discussion(): void
    {
        app(UserServiceInterface::class)->anonymize($this->ana->id);

        $this->get($this->url($this->ana->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filter.authorName', 'Anónimo')->where('filter.count', 13)->where('posts.0.authorName', 'Anónimo')->etc());
    }

    public function test_a_deactivated_account_keeps_its_messages_under_its_name(): void
    {
        $this->luis->delete();

        $this->get($this->url($this->luis->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filter.authorName', 'Luis')->where('filter.count', 12)->etc());
    }

    public function test_hidden_and_deleted_messages_count_as_in_the_discussion_but_only_their_readers_read_them(): void
    {
        $repository = app(PostRepositoryInterface::class);
        $hidden = $repository->withNumbers($this->discussion->id(), [3])[0];
        $hidden->hide('Spoiler');
        $repository->save($hidden);
        $deleted = $repository->withNumbers($this->discussion->id(), [5])[0];
        $deleted->deleteByAuthor(new DateTimeImmutable('2026-11-11 10:00:00'));
        $repository->save($deleted);

        // A stranger sees the marker of each, without its content, and they count
        $this->actingAs($this->luis)->get($this->url($this->ana->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.count', 13)
                ->where('posts.1.number', 3)->where('posts.1.status', 'hidden')->where('posts.1.html', null)->where('posts.1.placeholder', fn ($text): bool => $text !== null)
                ->where('posts.2.number', 5)->where('posts.2.status', 'deleted')->where('posts.2.html', null)
                ->etc());

        // The author reads the hidden one (not the one they deleted); moderation reads both
        $this->actingAs($this->ana)->get($this->url($this->ana->id))
            ->assertInertia(fn (Assert $page) => $page->where('posts.1.html', fn ($html): bool => is_string($html) && str_contains($html, 'Mensaje 3'))->where('posts.2.html', null)->etc());
        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))->get($this->url($this->ana->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.1.html', fn ($html): bool => is_string($html) && str_contains($html, 'Mensaje 3'))
                ->where('posts.2.html', fn ($html): bool => is_string($html) && str_contains($html, 'Mensaje 5'))
                ->etc());
    }

    public function test_messages_awaiting_approval_come_last_for_their_author_and_moderation_and_are_not_counted(): void
    {
        $pending = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->ana->id), self::aBody('Pendiente de Ana'), new DateTimeImmutable('2026-11-11 09:00:00'), true);
        $this->assertNull($pending->number());
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);

        foreach ([$this->ana, $moderator] as $reader) {
            // Not on the first page: they go with the last one, as in the discussion
            $this->actingAs($reader)->get($this->url($this->ana->id))
                ->assertInertia(fn (Assert $page) => $page->where('filter.count', 13)->where('posts', fn ($posts): bool => $posts->pluck('number')->all() === [1, 3, 5, 7, 9, 11, 13, 15, 17, 19])->etc());
            $this->actingAs($reader)->get($this->url($this->ana->id).'&pagina=2')
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filter.count', 13)
                    ->where('posts', fn ($posts): bool => $posts->pluck('number')->all() === [21, 23, 25, null])
                    ->where('posts.3.status', 'pending')
                    ->etc());
        }

        // Somebody else does not see it
        $this->actingAs($this->luis)->get($this->url($this->ana->id).'&pagina=2')
            ->assertInertia(fn (Assert $page) => $page->where('posts', fn ($posts): bool => $posts->pluck('number')->all() === [21, 23, 25])->etc());
    }

    public function test_the_address_of_a_merged_discussion_keeps_the_filter(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        [$into] = $this->aDiscussion($this->category, '2026-11-12 10:00:00', title: 'Destino');
        app(ForumModeration::class)->merge($moderator, $this->discussion->id(), $into->id());

        $this->get($this->url($this->ana->id))
            ->assertRedirect($this->discussionUrl($into).'?autor='.$this->ana->id)
            ->assertStatus(301);
    }

    public function test_a_wrong_slug_redirects_to_the_right_one_keeping_the_filter_and_the_page(): void
    {
        $number = (int) $this->discussion->number();

        $this->get("/foro/t/{$number}-otro-titulo?autor={$this->ana->id}&pagina=2")
            ->assertStatus(301)
            ->assertRedirect($this->discussionUrl().'?autor='.$this->ana->id.'&pagina=2');
    }

    private function postAs(UserModel $author, string $text, ?Character $as = null): Post
    {
        return app(ForumWriter::class)->reply(
            $this->discussion->id(),
            PostId::generate(),
            strtolower($author->id),
            self::aBody($text),
            new DateTimeImmutable('2026-11-10 19:00:00'),
            false,
            $as?->id(),
        );
    }

    private function url(string $author): string
    {
        return $this->discussionUrl().'?autor='.$author;
    }

    private function discussionUrl(?Discussion $discussion = null): string
    {
        $discussion ??= $this->discussion;

        return '/foro/t/'.(int) $discussion->number().'-'.str($discussion->title()->value)->slug();
    }
}
