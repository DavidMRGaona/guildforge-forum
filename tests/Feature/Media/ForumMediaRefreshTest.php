<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Media;

use App\Application\Media\DTOs\MediaUsageDTO;
use App\Application\Media\Services\MediaIndexerInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\ForumAccessMode;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Filament\Pages\ForumSettingsPage;
use Modules\Forum\Infrastructure\Media\CharacterPortraitMediaSource;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Whether the media library may show a post's images, or a character's portrait, depends on who
 * reads the post: the index is refreshed whenever that may have changed (forum#155), from what the
 * forum already tells the activity contract.
 */
final class ForumMediaRefreshTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use InteractsWithModulePanel;
    use UsesUtcStorage;

    private const string MAP = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    private const string PORTRAIT = 'forum/characters/2026/11/1c6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5b.png';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $ana;

    private UserModel $moderator;

    private MediaIndexerInterface&MockInterface $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->ana = UserModel::factory()->create();
        $this->moderator = $this->anAccount('forum:forum.moderate');
    }

    public function test_moving_a_discussion_to_a_private_category_refreshes_its_images_and_hides_them(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        app(ForumModeration::class)->move($this->moderator, $discussion->id(), $members->id());

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
        $this->assertTrue(app(PostImageMediaSource::class)->usagesFor($reply->id()->value)[0]->hidden);
    }

    public function test_hiding_a_discussion_refreshes_the_images_of_its_posts(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        app(ForumModeration::class)->hide($this->moderator, $discussion->id(), 'Spam');

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
    }

    public function test_hiding_a_post_whose_image_only_an_earlier_version_holds_refreshes_it(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);
        // Its current body names no image, so only the activity contract tells the library
        app(ForumWriter::class)->editPost($reply->id(), self::aBody('Sin imagen'), null, new DateTimeImmutable('2026-11-11 09:10:00'));

        $this->watchIndexer();
        app(ForumModeration::class)->hidePost($this->moderator, $reply->id(), 'Destripe');

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
    }

    public function test_approving_a_pending_post_refreshes_its_images_and_shows_them(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $pending = $this->replyWithImage($discussion, needsApproval: true);
        $this->assertTrue(app(PostImageMediaSource::class)->usagesFor($pending->id()->value)[0]->hidden);

        $this->watchIndexer();
        app(ForumModeration::class)->approve($this->moderator, $pending->id());

        $this->assertRefreshed(PostImageMediaSource::TYPE, $pending->id()->value);
        $this->assertFalse(app(PostImageMediaSource::class)->usagesFor($pending->id()->value)[0]->hidden);
    }

    public function test_splitting_posts_into_a_private_category_refreshes_and_hides_their_images(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        app(ForumModeration::class)->split($this->moderator, $discussion->id(), [2], 'Aparte', $members->id());

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
        $this->assertTrue(app(PostImageMediaSource::class)->usagesFor($reply->id()->value)[0]->hidden);
    }

    public function test_merging_into_a_private_discussion_refreshes_and_hides_the_images_that_move(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        [$absorbed] = $this->aDiscussion($this->general);
        $moved = $this->replyWithImage($absorbed);
        [$into] = $this->aDiscussion($members, '2026-11-10 19:00:00', title: 'Plan secreto');

        $this->watchIndexer();
        app(ForumModeration::class)->merge($this->moderator, $absorbed->id(), $into->id());

        $this->assertRefreshed(PostImageMediaSource::TYPE, $moved->id()->value);
        $this->assertTrue(app(PostImageMediaSource::class)->usagesFor($moved->id()->value)[0]->hidden);
    }

    public function test_renaming_a_discussion_refreshes_the_titles_of_its_images(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        app(ForumModeration::class)->rename($this->moderator, $discussion->id(), 'Sesión del sábado');

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
        $this->assertSame('Sesión del sábado, mensaje 2', app(PostImageMediaSource::class)->usagesFor($reply->id()->value)[0]->title);
    }

    public function test_narrowing_who_reads_a_category_refreshes_the_images_it_holds(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        $this->general->changeAccess(CategoryAudience::associationMembers(), $this->general->writeAudience(), $this->general->moderators());
        app(CategoryManager::class)->save($this->general);

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
        $this->assertTrue(app(PostImageMediaSource::class)->usagesFor($reply->id()->value)[0]->hidden);
    }

    public function test_deleting_a_discussion_refreshes_its_images_even_though_its_posts_are_gone(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        app(ForumModeration::class)->delete($this->moderator, $discussion->id());

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
    }

    public function test_a_post_without_images_refreshes_nothing(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->aReply($discussion, '2026-11-11 09:30:00');

        $this->watchIndexer();
        app(ForumModeration::class)->hidePost($this->moderator, $reply->id());

        $this->indexer->shouldNotHaveReceived('refresh', [PostImageMediaSource::TYPE, $reply->id()->value]);
    }

    public function test_moving_the_only_post_of_a_character_to_a_private_category_refreshes_and_hides_its_portrait(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        $character = $this->aCharacterWithPortrait();
        [$discussion] = $this->aDiscussion($this->general);
        $this->replyAs($discussion, $character);
        $this->assertFalse($this->portraitUsage($character)->hidden);

        $this->watchIndexer();
        app(ForumModeration::class)->move($this->moderator, $discussion->id(), $members->id());

        $this->assertRefreshed(CharacterPortraitMediaSource::TYPE, $character->id()->value);
        $this->assertTrue($this->portraitUsage($character)->hidden);
    }

    public function test_the_first_post_as_a_character_refreshes_its_portrait(): void
    {
        $character = $this->aCharacterWithPortrait();
        [$discussion] = $this->aDiscussion($this->general);

        $this->watchIndexer();
        $this->replyAs($discussion, $character);

        $this->assertRefreshed(CharacterPortraitMediaSource::TYPE, $character->id()->value);
    }

    public function test_deleting_the_discussion_a_character_spoke_in_refreshes_its_portrait(): void
    {
        $character = $this->aCharacterWithPortrait();
        [$discussion] = $this->aDiscussion($this->general);
        $this->replyAs($discussion, $character);

        $this->watchIndexer();
        app(ForumModeration::class)->delete($this->moderator, $discussion->id());

        $this->assertRefreshed(CharacterPortraitMediaSource::TYPE, $character->id()->value);
        $this->assertTrue($this->portraitUsage($character)->hidden);
    }

    public function test_a_post_that_stops_speaking_as_a_character_refreshes_the_character_it_left(): void
    {
        $character = $this->aCharacterWithPortrait();
        [$discussion] = $this->aDiscussion($this->general);
        $post = $this->replyAs($discussion, $character);

        $this->watchIndexer();
        DB::transaction(static fn () => PostModel::query()->findOrFail($post->id()->value)->update(['character_id' => null]));

        $this->assertRefreshed(CharacterPortraitMediaSource::TYPE, $character->id()->value);
    }

    public function test_a_subcategory_follows_its_parent_when_who_reads_the_parent_narrows(): void
    {
        $sub = $this->aCategoryFor(CategoryAudience::everyone(), 'sub', parent: $this->general);
        [$discussion] = $this->aDiscussion($sub);
        $reply = $this->replyWithImage($discussion);
        $this->assertFalse(app(PostImageMediaSource::class)->usagesFor($reply->id()->value)[0]->hidden);

        $this->watchIndexer();
        // Straight through the repository: the category editor would narrow the subcategory first
        $this->general->changeAccess(CategoryAudience::associationMembers(), $this->general->writeAudience(), $this->general->moderators());
        app(CategoryRepositoryInterface::class)->save($this->general);

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
        $this->assertTrue(app(PostImageMediaSource::class)->usagesFor($reply->id()->value)[0]->hidden);
    }

    public function test_turning_the_forum_into_a_preview_from_its_settings_page_refreshes_and_hides_its_images(): void
    {
        $this->registerOnAdminPanel([], [ForumSettingsPage::class]);
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);
        $this->actingAs($this->anAccount('forum:settings.manage'));

        $this->watchIndexer();
        Livewire::test(ForumSettingsPage::class)
            ->set('data.access', ForumAccessMode::Preview->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
        $this->assertTrue(app(PostImageMediaSource::class)->usagesFor($reply->id()->value)[0]->hidden);
    }

    /**
     * From here on, refreshes reach a spy instead of the index. Scoped instances are dropped, as a
     * new request or job does, so what an earlier write remembered never hides a refresh.
     */
    private function watchIndexer(): void
    {
        $this->indexer = Mockery::spy(MediaIndexerInterface::class);
        $this->app->instance(MediaIndexerInterface::class, $this->indexer);
        $this->app->forgetScopedInstances();
    }

    private function assertRefreshed(string $type, string $recordId): void
    {
        $this->indexer->shouldHaveReceived('refresh')->with($type, $recordId)->atLeast()->once();
    }

    private function replyWithImage(Discussion $discussion, bool $needsApproval = false): Post
    {
        $body = new PostBody([
            'type' => 'doc',
            'content' => [['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::MAP]]],
        ], '');

        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $this->ana->id, $body, new DateTimeImmutable('2026-11-11 09:00:00'), $needsApproval);
    }

    private function replyAs(Discussion $discussion, Character $character): Post
    {
        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $this->ana->id, self::aBody('En personaje'), new DateTimeImmutable('2026-11-11 09:30:00'), false, $character->id());
    }

    private function aCharacterWithPortrait(): Character
    {
        $character = $this->aCharacterOf($this->ana->id);
        $character->changePortrait(self::PORTRAIT, new DateTimeImmutable('2026-11-12 09:30:00'));
        app(CharacterRepositoryInterface::class)->save($character);

        return $character;
    }

    private function portraitUsage(Character $character): MediaUsageDTO
    {
        $usages = app(CharacterPortraitMediaSource::class)->usagesFor($character->id()->value);
        $this->assertCount(1, $usages);

        return $usages[0];
    }
}
