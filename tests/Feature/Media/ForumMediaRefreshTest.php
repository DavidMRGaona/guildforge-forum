<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Media;

use App\Application\Media\DTOs\MediaUsageDTO;
use App\Application\Media\Services\MediaIndexerInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Activity\ForumActivityChanges;
use Modules\Forum\Infrastructure\Media\CharacterPortraitMediaSource;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
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

    public function test_hiding_a_post_refreshes_its_images(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        app(ForumModeration::class)->hidePost($this->moderator, $reply->id(), 'Destripe');

        $this->assertRefreshed(PostImageMediaSource::TYPE, $reply->id()->value);
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

    public function test_turning_the_forum_into_a_preview_refreshes_its_images(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $reply = $this->replyWithImage($discussion);

        $this->watchIndexer();
        app(ModuleSettingsStoreInterface::class)->store('forum', ['access' => 'preview']);
        app(ForumActivityChanges::class)->everything();

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

    private function replyWithImage(Discussion $discussion): Post
    {
        $body = new PostBody([
            'type' => 'doc',
            'content' => [['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => self::MAP]]],
        ], '');

        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $this->ana->id, $body, new DateTimeImmutable('2026-11-11 09:00:00'), false);
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
