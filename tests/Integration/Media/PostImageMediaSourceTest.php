<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Media;

use App\Application\Media\Contracts\ShowsMediaPreviews;
use App\Application\Media\DTOs\MediaUsageDTO;
use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Application\Media\Services\MediaSourceRegistryInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Media\PostImageMediaSource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;
use Throwable;

/**
 * An image counts as used while any version of a post shows it, whatever the post's status, and
 * the media library shows it only while every member may read it: the post is visible and
 * published, its discussion listed, and its category one every signed-in member reads (forum#155).
 * Anything else is hidden, under a title that gives nothing away.
 */
final class PostImageMediaSourceTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MAP = 'forum/posts/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    private const string PORTRAIT = 'forum/posts/2026/11/1c6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5b.png';

    private const string UNUSED = 'forum/posts/2026/11/2d6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5c.jpg';

    private const string COPY = 'forum/external/2026/11/3e6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5d.png';

    private const string MASKED = 'Mensaje del foro';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->moderator = $this->anAccount('forum:forum.moderate');
    }

    public function test_the_module_registers_the_source_for_its_folder_and_shows_its_previews(): void
    {
        $source = app(MediaSourceRegistryInterface::class)->source(PostImageMediaSource::TYPE);

        $this->assertInstanceOf(ShowsMediaPreviews::class, $source);
        $this->assertInstanceOf(PostImageMediaSource::class, $source);
        $this->assertSame(['forum/posts/', 'forum/external/'], $source->folders());
        $this->assertFalse($source->hidden());
    }

    public function test_images_of_the_current_body_and_of_earlier_versions_are_used_once_each(): void
    {
        [$discussion, $first] = $this->aDiscussion($this->aCategory());
        $reply = $this->aReply($discussion, '2026-11-10 18:30:00');
        $this->writer()->editPost($reply->id(), $this->withImages([self::MAP, self::PORTRAIT]), null, new DateTimeImmutable('2026-11-10 18:40:00'));
        // The map leaves the post but stays in its history
        $this->writer()->editPost($reply->id(), $this->withImages([self::PORTRAIT]), null, new DateTimeImmutable('2026-11-10 18:50:00'));
        $this->writer()->deletePostByAuthor($reply->id(), new DateTimeImmutable('2026-11-10 19:30:00'));

        $usages = iterator_to_array($this->source()->usages(), false);

        $this->assertEqualsCanonicalizing([self::MAP, self::PORTRAIT], array_map(static fn ($usage): string => $usage->storedPath, $usages));
        $this->assertSame([$reply->id()->value], array_values(array_unique(array_map(static fn ($usage): string => $usage->recordId, $usages))));
        $this->assertSame('Imagen del mensaje', $usages[0]->role);
        $this->assertCount(2, $this->source()->usagesFor($reply->id()->value));
        $this->assertSame([], $this->source()->usagesFor($first->id()->value));
        $this->assertSame([], $this->source()->usagesFor('not-a-uuid'));

        $this->assertTrue($this->source()->usesFile($this->fileId(self::MAP)));
        $this->assertTrue($this->source()->usesFile($this->fileId(self::PORTRAIT)));
        $this->assertFalse($this->source()->usesFile($this->fileId(self::UNUSED)));
    }

    public function test_the_image_of_a_post_every_member_reads_is_shown_with_its_discussion_and_number(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::everyone(), 'general'));
        $reply = $this->replyWith($discussion, [self::MAP]);

        $usage = $this->onlyUsageOf($reply);

        $this->assertFalse($usage->hidden);
        $this->assertSame('Sesión del viernes, mensaje 2', $usage->title);
    }

    public function test_a_category_any_signed_in_member_reads_shows_its_images_too(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas'));
        $reply = $this->replyWith($discussion, [self::MAP]);

        $this->assertFalse($this->onlyUsageOf($reply)->hidden);
    }

    public function test_an_image_only_an_earlier_version_holds_is_hidden_since_only_moderators_read_versions(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $reply = $this->replyWith($discussion, [self::MAP, self::PORTRAIT]);
        $this->writer()->editPost($reply->id(), $this->withImages([self::PORTRAIT]), null, new DateTimeImmutable('2026-11-10 18:50:00'));

        $usages = $this->usagesByPath($reply);

        $this->assertTrue($usages[self::MAP]->hidden);
        $this->assertFalse($usages[self::PORTRAIT]->hidden);
        // The discussion is public: the title gives nothing away
        $this->assertSame('Sesión del viernes, mensaje 2', $usages[self::MAP]->title);
    }

    public function test_a_post_hidden_by_moderation_is_hidden_under_a_neutral_title(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $reply = $this->replyWith($discussion, [self::MAP]);

        app(ForumModeration::class)->hidePost($this->moderator, $reply->id(), 'Destripe');

        $this->assertHiddenAndMasked($reply);

        app(ForumModeration::class)->showPost($this->moderator, $reply->id());

        $this->assertFalse($this->onlyUsageOf($reply)->hidden);
    }

    public function test_a_post_pending_approval_is_hidden_until_it_is_approved(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $pending = $this->writer()->reply($discussion->id(), PostId::generate(), null, $this->withImages([self::MAP]), new DateTimeImmutable('2026-11-10 18:30:00'), true);

        $this->assertHiddenAndMasked($pending);

        app(ForumModeration::class)->approve($this->moderator, $pending->id());

        $this->assertFalse($this->onlyUsageOf($pending)->hidden);
    }

    public function test_a_post_its_author_deleted_is_hidden(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $reply = $this->replyWith($discussion, [self::MAP]);

        $this->writer()->deletePostByAuthor($reply->id(), new DateTimeImmutable('2026-11-10 19:30:00'));

        $this->assertHiddenAndMasked($reply);
    }

    public function test_every_post_of_a_hidden_discussion_is_hidden(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $reply = $this->replyWith($discussion, [self::MAP]);

        app(ForumModeration::class)->hide($this->moderator, $discussion->id(), 'Spam');

        $this->assertHiddenAndMasked($reply);
    }

    public function test_the_opening_post_of_a_discussion_awaiting_approval_is_hidden(): void
    {
        [, $opening] = $this->aDiscussion($this->aCategory(), needsApproval: true);
        $this->writer()->editPost($opening->id(), $this->withImages([self::MAP]), null, new DateTimeImmutable('2026-11-10 18:40:00'));

        $this->assertHiddenAndMasked($opening);
    }

    public function test_categories_narrower_than_every_member_hide_their_images_under_a_neutral_title(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        $narrower = [
            $members,
            $this->aCategoryFor(CategoryAudience::roles([(string) Str::uuid()]), 'junta'),
            // Open itself, but under a category only association members read
            $this->aCategoryFor(CategoryAudience::signedIn(), 'bajo-socios', parent: $members),
        ];

        foreach ($narrower as $category) {
            [$discussion] = $this->aDiscussion($category, title: 'Secreto de '.$category->slug()->value);
            $reply = $this->replyWith($discussion, [self::MAP]);

            $this->assertHiddenAndMasked($reply);
        }
    }

    public function test_nothing_is_shown_while_the_forum_is_a_preview_members_do_not_read(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $reply = $this->replyWith($discussion, [self::MAP]);
        app(ModuleSettingsStoreInterface::class)->store('forum', ['access' => 'preview']);

        $this->assertHiddenAndMasked($reply);
    }

    public function test_moving_a_discussion_to_a_private_category_hides_its_images(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        [$discussion] = $this->aDiscussion($this->aCategory());
        $reply = $this->replyWith($discussion, [self::MAP]);
        $this->assertFalse($this->onlyUsageOf($reply)->hidden);

        app(ForumModeration::class)->move($this->moderator, $discussion->id(), $members->id());

        $this->assertHiddenAndMasked($reply);
    }

    public function test_the_whole_walk_hides_the_same_usages_as_one_record(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        [$public] = $this->aDiscussion($this->aCategory());
        [$private] = $this->aDiscussion($members, title: 'Plan secreto');
        $shown = $this->replyWith($public, [self::MAP]);
        $hidden = $this->replyWith($private, [self::PORTRAIT]);

        $byRecord = [];
        foreach ($this->source()->usages() as $usage) {
            $byRecord[$usage->recordId] = $usage;
        }

        $this->assertFalse($byRecord[$shown->id()->value]->hidden);
        $this->assertTrue($byRecord[$hidden->id()->value]->hidden);
        $this->assertSame(self::MASKED, $byRecord[$hidden->id()->value]->title);
    }

    public function test_a_category_rule_that_cannot_be_read_fails_the_walk_instead_of_showing_anything(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $this->replyWith($discussion, [self::MAP]);
        $broken = $this->aCategoryFor(CategoryAudience::everyone(), 'rota');
        DB::table('forum_categories')->where('id', $broken->id()->value)->update(['view_rule' => null]);

        $this->expectException(Throwable::class);

        iterator_to_array($this->source()->usages(), false);
    }

    public function test_the_copy_of_an_image_from_another_site_is_used_while_a_post_shows_it(): void
    {
        [, $post] = $this->aDiscussion($this->aCategory());
        $external = new PostBody([
            'type' => 'doc',
            'content' => [['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => 'https://i.imgur.com/AbC123.png', 'path' => self::COPY]]],
        ], '');
        $this->writer()->editPost($post->id(), $external, null, new DateTimeImmutable('2026-11-10 18:40:00'));

        $this->assertTrue($this->source()->usesFile($this->fileId(self::COPY)));
        $this->assertSame([self::COPY], array_map(static fn ($usage): string => $usage->storedPath, $this->source()->usagesFor($post->id()->value)));
        $this->assertFalse($this->source()->usagesFor($post->id()->value)[0]->hidden);
    }

    public function test_deleting_the_discussion_frees_its_images(): void
    {
        [$discussion, $post] = $this->aDiscussion($this->aCategory());
        $this->writer()->editPost($post->id(), $this->withImages([self::MAP]), null, new DateTimeImmutable('2026-11-10 18:40:00'));
        $this->assertTrue($this->source()->usesFile($this->fileId(self::MAP)));

        $this->writer()->deleteDiscussion($discussion->id());

        $this->assertFalse($this->source()->usesFile($this->fileId(self::MAP)));
        $this->assertSame([], iterator_to_array($this->source()->usages(), false));
    }

    public function test_only_posts_whose_documents_name_an_image_or_an_embed_may_hold_images(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->aCategory());
        $reply = $this->replyWith($discussion, [self::MAP]);

        $this->assertTrue($this->source()->mayHoldImages($reply->id()->value));
        $this->assertFalse($this->source()->mayHoldImages($opening->id()->value));
        $this->assertFalse($this->source()->mayHoldImages('not-a-uuid'));
    }

    private function assertHiddenAndMasked(Post $post): void
    {
        $usages = $this->source()->usagesFor($post->id()->value);

        $this->assertNotSame([], $usages);
        foreach ($usages as $usage) {
            $this->assertTrue($usage->hidden, 'The image of a post not every member reads must be hidden.');
            $this->assertSame(self::MASKED, $usage->title);
        }
    }

    private function onlyUsageOf(Post $post): MediaUsageDTO
    {
        $usages = $this->source()->usagesFor($post->id()->value);
        $this->assertCount(1, $usages);

        return $usages[0];
    }

    /**
     * @return array<string, MediaUsageDTO>
     */
    private function usagesByPath(Post $post): array
    {
        $byPath = [];
        foreach ($this->source()->usagesFor($post->id()->value) as $usage) {
            $byPath[$usage->storedPath] = $usage;
        }

        return $byPath;
    }

    /**
     * @param  list<string>  $paths
     */
    private function replyWith(Discussion $discussion, array $paths): Post
    {
        return $this->writer()->reply(
            $discussion->id(),
            PostId::generate(),
            null,
            $this->withImages($paths),
            new DateTimeImmutable('2026-11-10 18:30:00'),
            false,
        );
    }

    private function source(): PostImageMediaSource
    {
        return app(PostImageMediaSource::class);
    }

    private function writer(): ForumWriter
    {
        return app(ForumWriter::class);
    }

    private function fileId(string $path): MediaFileId
    {
        $id = app(MediaFileIdResolverInterface::class)->fromStoredPath($path);
        $this->assertInstanceOf(MediaFileId::class, $id);

        return $id;
    }

    /**
     * @param  list<string>  $paths
     */
    private function withImages(array $paths): PostBody
    {
        return new PostBody([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mirad']]],
                ...array_map(static fn (string $path): array => ['type' => 'image', 'attrs' => ['origin' => 'upload', 'src' => $path]], $paths),
            ],
        ], 'Mirad');
    }
}
