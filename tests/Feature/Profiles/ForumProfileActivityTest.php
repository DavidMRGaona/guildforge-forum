<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Profiles;

use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ResolvedProfileDTO;
use App\Application\Profiles\DTOs\ResolvedProfileSectionDTO;
use App\Application\Profiles\DTOs\ResolvedProfileStatDTO;
use App\Application\Profiles\Services\ProfileRendererInterface;
use App\Application\Profiles\Services\ProfileSectionRegistryInterface;
use App\Application\Profiles\Services\ProfileStatRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserProfileModel;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\ProfileActivity;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Profiles\LatestPostsProfileSection;
use Modules\Forum\Infrastructure\Profiles\PostsProfileStat;
use Modules\Forum\Infrastructure\Profiles\TopicsProfileStat;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The forum on a public profile (forum#135): the latest messages and two counters, each for the
 * person looking, with the very rules of the forum's own pages, and nothing they could not read there.
 */
final class ForumProfileActivityTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string SECTION = 'forum.latest-posts';

    private const string POSTS = 'forum.posts';

    private const string TOPICS = 'forum.topics';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $ana;

    private UserModel $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->ana = UserModel::factory()->create(['name' => 'ana.login', 'display_name' => 'Ana']);
        $this->luis = UserModel::factory()->create(['name' => 'luis.login', 'display_name' => 'Luis']);
    }

    public function test_the_module_offers_the_section_and_both_counters(): void
    {
        $section = app(ProfileSectionRegistryInterface::class)->find(self::SECTION);
        $this->assertNotNull($section);
        $this->assertSame(LatestPostsProfileSection::class, $section->class);
        $this->assertSame('forum', $section->module);

        $this->assertSame(PostsProfileStat::class, app(ProfileStatRegistryInterface::class)->find(self::POSTS)?->class);
        $this->assertSame(TopicsProfileStat::class, app(ProfileStatRegistryInterface::class)->find(self::TOPICS)?->class);

        $built = app(LatestPostsProfileSection::class);
        $this->assertTrue($built->enabledByDefault());
        $this->assertSame('main', $built->region()->value);
        $this->assertSame('components/profile/LatestPostsSection.vue', $built->component());
    }

    public function test_a_guest_sees_the_latest_five_messages_with_their_topic_category_date_excerpt_and_address(): void
    {
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Partida del sábado');
        for ($i = 2; $i <= 7; $i++) {
            $this->postAs($this->ana, $discussion, "Mensaje {$i}", sprintf('2026-11-10 1%d:00:00', $i));
        }
        $slug = '/foro/t/'.(int) $discussion->number().'-partida-del-sabado';

        $posts = $this->posts($this->render(null));

        $this->assertCount(5, $posts);
        $this->assertSame([$slug.'/7', $slug.'/6', $slug.'/5', $slug.'/4', $slug.'/3'], array_column($posts, 'url'));
        $this->assertSame([
            'title' => 'Partida del sábado',
            'url' => $slug.'/7',
            'category' => 'General',
            'categoryUrl' => '/foro/c/general',
            'excerpt' => 'Mensaje 7',
            'publishedAt' => '2026-11-10T18:00:00+01:00',
            'publishedLabel' => $posts[0]['publishedLabel'],
        ], $posts[0]);
        $this->assertIsString($posts[0]['publishedLabel']);
        $this->assertNotSame('', $posts[0]['publishedLabel']);
    }

    public function test_the_opening_message_links_to_the_topic_itself(): void
    {
        [$discussion] = $this->aDiscussion($this->general, authorId: $this->anaId(), title: 'Presentaciones');

        $posts = $this->posts($this->render(null));

        $this->assertSame('/foro/t/'.(int) $discussion->number().'-presentaciones', $posts[0]['url']);
        $this->assertSame('Primer mensaje', $posts[0]['excerpt']);
    }

    public function test_the_counters_count_messages_and_opened_topics_without_a_link_of_their_own(): void
    {
        [$first] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Uno');
        $this->aDiscussion($this->general, '2026-11-10 11:00:00', $this->anaId(), title: 'Dos');
        [$other] = $this->aDiscussion($this->general, '2026-11-10 12:00:00', strtolower((string) $this->luis->id), title: 'De Luis');
        $this->postAs($this->ana, $first, 'Respuesta', '2026-11-10 13:00:00');
        $this->postAs($this->ana, $other, 'Otra respuesta', '2026-11-10 14:00:00');

        $profile = $this->render(null);

        $this->assertSame(4, $this->stat($profile, self::POSTS)?->value);
        $this->assertSame(2, $this->stat($profile, self::TOPICS)?->value);
        $this->assertNull($this->stat($profile, self::POSTS)?->url);
        $this->assertNull($this->stat($profile, self::TOPICS)?->url);
    }

    public function test_a_restricted_category_shows_and_counts_only_for_whoever_may_read_it(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Abierto');
        [$closed] = $this->aDiscussion($members, '2026-11-10 11:00:00', $this->anaId(), title: 'Solo socios');
        $this->postAs($this->ana, $closed, 'Dentro', '2026-11-10 12:00:00');

        $guest = $this->render(null);
        $this->assertSame(['Abierto'], array_column($this->posts($guest), 'title'));
        $this->assertSame(1, $this->stat($guest, self::POSTS)?->value);
        $this->assertSame(1, $this->stat($guest, self::TOPICS)?->value);

        $member = $this->render($this->luis);
        $this->assertSame(['Solo socios', 'Solo socios', 'Abierto'], array_column($this->posts($member), 'title'));
        $this->assertSame(3, $this->stat($member, self::POSTS)?->value);
        $this->assertSame(2, $this->stat($member, self::TOPICS)?->value);
    }

    public function test_writing_only_where_the_viewer_cannot_read_draws_nothing_and_counts_nothing_rather_than_zero(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        $this->aDiscussion($members, authorId: $this->anaId(), title: 'Solo socios');

        $guest = $this->render(null);

        $this->assertNull($this->section($guest));
        $this->assertNull($this->stat($guest, self::POSTS));
        $this->assertNull($this->stat($guest, self::TOPICS));
    }

    public function test_someone_who_never_wrote_has_no_section_and_no_counters(): void
    {
        $profile = $this->render($this->luis);

        $this->assertNull($this->section($profile));
        $this->assertNull($this->stat($profile, self::POSTS));
        $this->assertNull($this->stat($profile, self::TOPICS));
    }

    public function test_a_forum_in_preview_is_nothing_to_whoever_may_not_see_it(): void
    {
        $this->aDiscussion($this->general, authorId: $this->anaId(), title: 'En pruebas');
        config(['modules.settings.forum.access' => 'preview']);
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);

        foreach ([null, $this->luis, $moderator, $this->ana] as $viewer) {
            $profile = $this->render($viewer);
            $this->assertNull($this->section($profile));
            $this->assertNull($this->stat($profile, self::POSTS));
            $this->assertNull($this->stat($profile, self::TOPICS));
        }

        $previewer = $this->render($this->anAccount(ForumVisibility::PERMISSION));
        $this->assertSame(['En pruebas'], array_column($this->posts($previewer), 'title'));
        $this->assertSame(1, $this->stat($previewer, self::POSTS)?->value);
    }

    public function test_hidden_deleted_and_pending_messages_never_show_nor_count_not_even_for_moderation_or_their_author(): void
    {
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Tema');
        $hidden = $this->postAs($this->ana, $discussion, 'Oculto por moderación', '2026-11-10 11:00:00');
        $deleted = $this->postAs($this->ana, $discussion, 'Borrado por su autora', '2026-11-10 12:00:00');
        $this->postAs($this->ana, $discussion, 'Pendiente de aprobar', '2026-11-10 13:00:00', pending: true);
        $repository = app(PostRepositoryInterface::class);
        $hidden->hide('Spoiler');
        $repository->save($hidden);
        $deleted->deleteByAuthor(new DateTimeImmutable('2026-11-10 14:00:00'));
        $repository->save($deleted);

        foreach ([null, $this->ana, $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM)] as $viewer) {
            $profile = $this->render($viewer);
            $this->assertSame(['Primer mensaje'], array_column($this->posts($profile), 'excerpt'));
            $this->assertSame(1, $this->stat($profile, self::POSTS)?->value);
        }
    }

    public function test_hidden_topics_and_topics_awaiting_approval_never_show_nor_count(): void
    {
        [$hidden] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Oculto');
        $hidden->hide();
        app(DiscussionRepositoryInterface::class)->save($hidden);
        $this->aDiscussion($this->general, '2026-11-10 11:00:00', $this->anaId(), needsApproval: true, title: 'Pendiente');
        $this->aDiscussion($this->general, '2026-11-10 12:00:00', $this->anaId(), title: 'Visible');

        foreach ([null, $this->ana, $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM)] as $viewer) {
            $profile = $this->render($viewer);
            $this->assertSame(['Visible'], array_column($this->posts($profile), 'title'));
            $this->assertSame(1, $this->stat($profile, self::POSTS)?->value);
            $this->assertSame(1, $this->stat($profile, self::TOPICS)?->value);
        }
    }

    public function test_messages_as_a_shared_character_show_and_count_only_for_their_writer_and_moderation(): void
    {
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Campaña');
        $shared = $this->aSharedCharacterIn($this->general);
        $this->postAs($this->ana, $discussion, 'Habla el mercader', '2026-11-10 11:00:00', $shared);

        foreach ([null, $this->luis] as $viewer) {
            $profile = $this->render($viewer);
            $this->assertSame(['Primer mensaje'], array_column($this->posts($profile), 'excerpt'));
            $this->assertSame(1, $this->stat($profile, self::POSTS)?->value);
        }

        foreach ([$this->ana, $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM)] as $viewer) {
            $profile = $this->render($viewer);
            $this->assertSame(['Habla el mercader', 'Primer mensaje'], array_column($this->posts($profile), 'excerpt'));
            $this->assertSame(2, $this->stat($profile, self::POSTS)?->value);
        }
    }

    public function test_a_topic_opened_as_a_shared_character_counts_only_for_its_writer_and_moderation(): void
    {
        $shared = $this->aSharedCharacterIn($this->general);
        $discussion = Discussion::start(DiscussionId::generate(), $this->general->id(), $this->anaId(), new DiscussionTitle('El mercader llega'), new DateTimeImmutable('2026-11-10 10:00:00'));
        app(ForumWriter::class)->startDiscussion($discussion, PostId::generate(), self::aBody('Buenas tardes, viajeros'), false, $shared->id());

        $guest = $this->render(null);
        $this->assertNull($this->section($guest));
        $this->assertNull($this->stat($guest, self::TOPICS));
        $this->assertNull($this->stat($guest, self::POSTS));

        $moderation = $this->render($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM));
        $this->assertSame(1, $this->stat($moderation, self::TOPICS)?->value);
        $this->assertSame(['Buenas tardes, viajeros'], array_column($this->posts($moderation), 'excerpt'));
    }

    public function test_in_character_and_out_of_character_messages_count_the_same(): void
    {
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Partida');
        $arwen = $this->aCharacterOf($this->anaId());
        $this->postAs($this->ana, $discussion, 'Como Arwen', '2026-11-10 11:00:00', $arwen);
        $this->postAs($this->ana, $discussion, 'Fuera de personaje', '2026-11-10 12:00:00');

        $profile = $this->render(null);

        $this->assertSame(['Fuera de personaje', 'Como Arwen', 'Primer mensaje'], array_column($this->posts($profile), 'excerpt'));
        $this->assertSame(3, $this->stat($profile, self::POSTS)?->value);
    }

    public function test_messages_as_a_personal_character_of_someone_without_a_public_name_follow_the_forum_rule(): void
    {
        $nameless = UserModel::factory()->create(['name' => 'sin.nombre', 'display_name' => null]);
        $id = strtolower((string) $nameless->id);
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $id, title: 'Partida');
        $this->postAs($nameless, $discussion, 'Como Arwen', '2026-11-10 11:00:00', $this->aCharacterOf($id));
        $owner = new ProfileOwnerDTO($id, 'miembro-a1b2c3', null);

        // Only its owner looks at such a profile; were anyone else to, the forum's rule would leave them out
        $other = $this->render($this->luis, $owner);
        $this->assertSame(['Primer mensaje'], array_column($this->posts($other), 'excerpt'));
        $this->assertSame(1, $this->stat($other, self::POSTS)?->value);

        $own = $this->render($nameless, $owner);
        $this->assertSame(['Como Arwen', 'Primer mensaje'], array_column($this->posts($own), 'excerpt'));
        $this->assertSame(2, $this->stat($own, self::POSTS)?->value);
    }

    public function test_the_excerpt_is_plain_text_without_quotes_and_names_only_public_names(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', strtolower((string) $this->luis->id), title: 'Tema');
        $nameless = UserModel::factory()->create(['name' => 'cuenta.secreta', 'display_name' => null]);
        $body = new PostBody(['type' => 'doc', 'content' => [
            ['type' => 'postQuote', 'attrs' => ['postId' => $opening->id()->value], 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texto citado de otra persona']]],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hola '],
                ['type' => 'mention', 'attrs' => ['id' => strtolower((string) $this->luis->id)]],
                ['type' => 'text', 'text' => ' y '],
                ['type' => 'mention', 'attrs' => ['id' => strtolower((string) $nameless->id)]],
                ['type' => 'text', 'text' => ', <b>mirad</b>', 'marks' => [['type' => 'bold']]],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('largo ', 80)]]],
        ]], 'Hola @Luis y @cuenta.secreta');
        app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $this->anaId(), $body, new DateTimeImmutable('2026-11-10 11:00:00'), false);

        $excerpt = $this->posts($this->render(null))[0]['excerpt'];

        $this->assertIsString($excerpt);
        $this->assertStringStartsWith('Hola @Luis y @', $excerpt);
        $this->assertStringContainsString('<b>mirad</b>', $excerpt);
        $this->assertStringNotContainsString('cuenta.secreta', $excerpt);
        $this->assertStringNotContainsString('citado', $excerpt);
        $this->assertStringNotContainsString("\n", $excerpt);
        $this->assertLessThanOrEqual(ProfileActivity::EXCERPT_LENGTH + 1, mb_strlen($excerpt));
    }

    public function test_nothing_private_about_the_owner_travels(): void
    {
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Tema');
        $this->postAs($this->ana, $discussion, 'Respuesta', '2026-11-10 11:00:00');

        $profile = $this->render(null);
        $json = (string) json_encode([$this->section($profile)?->props, $this->stat($profile, self::POSTS)?->toArray()]);

        $this->assertStringNotContainsString('ana.login', $json);
        $this->assertStringNotContainsString((string) $this->ana->email, $json);
        $this->assertStringNotContainsString($this->anaId(), $json);
        $this->assertStringNotContainsString($discussion->id()->value, $json);
    }

    public function test_the_queries_do_not_grow_with_the_messages(): void
    {
        $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Uno');
        $few = $this->countQueries(fn () => $this->render($this->luis));

        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        $arwen = $this->aCharacterOf($this->anaId());
        for ($i = 0; $i < 8; $i++) {
            [$discussion] = $this->aDiscussion($i % 2 === 0 ? $this->general : $members, sprintf('2026-11-11 1%d:00:00', $i), $this->anaId(), title: "Tema {$i}");
            $this->postAs($this->ana, $discussion, 'Como Arwen', sprintf('2026-11-11 1%d:30:00', $i), $arwen);
        }
        $many = $this->countQueries(fn () => $this->render($this->luis));

        $this->assertLessThanOrEqual($few + 2, $many);
    }

    public function test_the_profile_page_draws_the_section_and_the_counters_with_the_forum_component(): void
    {
        [$discussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', $this->anaId(), title: 'Tema');
        $this->postAs($this->ana, $discussion, 'Respuesta', '2026-11-10 11:00:00');
        $handle = UserProfileModel::query()->whereKey($this->anaId())->value('handle');
        $this->assertIsString($handle);
        $this->app->forgetScopedInstances();

        $this->actingAs($this->luis)->get('/miembros/'.$handle)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Profiles/Show')
                ->where('profile.sections.main.0.key', self::SECTION)
                ->where('profile.sections.main.0.module', 'forum')
                ->where('profile.sections.main.0.component', 'components/profile/LatestPostsSection.vue')
                ->where('profile.sections.main.0.label', 'Últimos mensajes en el foro')
                ->where('profile.sections.main.0.props.posts.0.excerpt', 'Respuesta')
                ->where('profile.stats', fn (Collection $stats): bool => $this->forumStats($stats->all()) === [
                    self::POSTS => ['Mensajes en el foro', 2, null],
                    self::TOPICS => ['Temas abiertos', 1, null],
                ])
                ->etc());
    }

    /**
     * The forum's counters on the page, by key: label, value and link.
     *
     * @param  array<array-key, mixed>  $stats
     * @return array<string, list<mixed>>
     */
    private function forumStats(array $stats): array
    {
        $found = [];
        foreach ($stats as $stat) {
            if (is_array($stat) && in_array($stat['key'] ?? null, [self::POSTS, self::TOPICS], true)) {
                $found[(string) $stat['key']] = [$stat['label'] ?? null, $stat['value'] ?? null, $stat['url'] ?? null];
            }
        }

        return $found;
    }

    private function render(?Authenticatable $viewer, ?ProfileOwnerDTO $owner = null): ResolvedProfileDTO
    {
        // Each profile is its own request: what is bound scoped (roles, categories) starts afresh
        $this->app->forgetScopedInstances();

        return app(ProfileRendererInterface::class)->render($owner ?? new ProfileOwnerDTO($this->anaId(), 'ana', 'Ana'), $viewer);
    }

    private function section(ResolvedProfileDTO $profile): ?ResolvedProfileSectionDTO
    {
        foreach ($profile->main as $section) {
            if ($section->key === self::SECTION) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(ResolvedProfileDTO $profile): array
    {
        $section = $this->section($profile);
        $this->assertNotNull($section, 'The section is not drawn.');
        $posts = $section->props['posts'] ?? null;
        $this->assertIsArray($posts);

        /** @var list<array<string, mixed>> $posts */
        return $posts;
    }

    private function stat(ResolvedProfileDTO $profile, string $key): ?ResolvedProfileStatDTO
    {
        foreach ($profile->stats as $stat) {
            if ($stat->key === $key) {
                return $stat;
            }
        }

        return null;
    }

    private function postAs(UserModel $author, Discussion $discussion, string $text, string $at, ?Character $as = null, bool $pending = false): Post
    {
        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), strtolower((string) $author->id), self::aBody($text), new DateTimeImmutable($at), $pending, $as?->id());
    }

    private function anaId(): string
    {
        return strtolower((string) $this->ana->id);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
