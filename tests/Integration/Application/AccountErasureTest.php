<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Application\Media\Services\MediaFileIdResolverInterface;
use App\Application\Services\UserServiceInterface;
use App\Domain\Events\UserAnonymized;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\AccountErasure;
use Modules\Forum\Application\Services\PostRenderer;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Infrastructure\Media\CharacterPortraitMediaSource;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CharacterModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\ErasedTables;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Anonymizing an account (core#461): what it said stays with the conversations it was part of,
 * under the site's anonymous name; what only mattered to the person goes; what moderation kept
 * as history stays; its characters lose their details and can never be used again.
 */
final class AccountErasureTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string PORTRAIT = 'forum/characters/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.webp';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $person;

    private UserModel $other;

    private Discussion $discussion;

    private Post $personsPost;

    private Post $othersPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->person = UserModel::factory()->create(['name' => 'Ana Pérez', 'display_name' => 'Anita', 'email' => 'ana@example.test']);
        $this->other = UserModel::factory()->create(['name' => 'Luis', 'display_name' => null]);
        $category = $this->aCategory();
        [$this->discussion, $this->personsPost] = $this->aDiscussion($category, authorId: $this->person->id);
        $this->othersPost = $this->aReply($this->discussion, '2026-11-10 19:00:00');
    }

    public function test_the_posts_stay_and_show_the_anonymous_name(): void
    {
        $this->anonymize();

        $this->assertSame($this->person->id, $this->postRow($this->personsPost)->author_id);
        $names = app(AccountDirectoryInterface::class)->namesOf([$this->person->id]);
        $this->assertSame([strtolower($this->person->id) => 'Anónimo'], $names);
        $this->assertNotContains('Ana Pérez', $names);
    }

    public function test_the_mentions_of_the_person_are_painted_with_the_anonymous_name_and_their_text_is_written_again(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hola '],
            ['type' => 'mention', 'attrs' => ['id' => strtolower($this->person->id)]],
        ]]]];
        DB::table('forum_posts')->where('id', $this->othersPost->id()->value)->update(['body' => json_encode($document), 'plain_text' => 'Hola @Anita']);
        DB::table('forum_post_revisions')->insert(['id' => (string) Str::uuid(), 'post_id' => $this->othersPost->id()->value, 'number' => 1, 'body' => json_encode($document), 'plain_text' => 'Hola @Anita', 'created_at' => '2026-11-10 19:30:00']);

        $this->anonymize();

        $html = app(PostRenderer::class)->toHtml([$this->othersPostBody()], null);
        $this->assertStringContainsString('@Anónimo', $html[0]);
        $this->assertStringNotContainsString('Anita', $html[0]);
        $this->assertSame('Hola @Anónimo', $this->postRow($this->othersPost)->plain_text);
        $this->assertSame('Hola @Anónimo', DB::table('forum_post_revisions')->where('post_id', $this->othersPost->id()->value)->value('plain_text'));
    }

    public function test_what_only_mattered_to_the_person_goes(): void
    {
        $this->seedEngagement($this->person);
        $this->seedEngagement($this->other);

        $this->anonymize();

        foreach (ErasedTables::columns() as [$table, $column]) {
            $this->assertSame(0, DB::table($table)->where($column, $this->person->id)->count(), "{$table}.{$column} still holds the person");
            $this->assertSame(1, DB::table($table)->where($column, $this->other->id)->count(), "{$table}.{$column} lost somebody else's row");
        }
    }

    public function test_the_forum_mutes_and_notices_of_the_person_go(): void
    {
        app(MailSubjectMuteServiceInterface::class)->mute($this->person, new MailSubjectDTO('forum.discussion', $this->discussion->id()->value, 'Sesión'));

        $this->anonymize();

        $this->assertSame(0, DB::table('user_mail_subject_mutes')->where('user_id', $this->person->id)->count());
    }

    public function test_reports_the_posts_received_and_suspensions_stay_as_moderation_history(): void
    {
        $this->seedEngagement($this->other);
        DB::table('forum_reports')->insert([
            'id' => (string) Str::uuid(), 'post_id' => $this->personsPost->id()->value, 'reporter_id' => $this->other->id,
            'reason' => 'spam', 'created_at' => '2026-11-11 10:00:00',
        ]);
        DB::table('forum_suspensions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->person->id, 'suspended_by' => $this->other->id,
            'reason' => 'Insultos', 'starts_at' => '2026-11-11 10:00:00', 'created_at' => '2026-11-11 10:00:00', 'updated_at' => '2026-11-11 10:00:00',
        ]);
        DB::table('forum_moderation_log')->insert([
            'id' => (string) Str::uuid(), 'action' => 'post_hidden', 'moderator_id' => $this->other->id,
            'subject_user_id' => $this->person->id, 'created_at' => '2026-11-11 10:00:00',
        ]);

        $this->anonymize();

        $this->assertSame(1, DB::table('forum_reports')->where('post_id', $this->personsPost->id()->value)->count());
        $this->assertSame(1, DB::table('forum_suspensions')->where('user_id', $this->person->id)->count());
        $this->assertSame(1, DB::table('forum_moderation_log')->where('subject_user_id', $this->person->id)->count());
    }

    public function test_the_characters_lose_their_details_and_are_retired_for_good(): void
    {
        $character = $this->aCharacterOf($this->person->id, 'Arwen');
        $character->changePortrait(self::PORTRAIT, new DateTimeImmutable);
        app(CharacterRepositoryInterface::class)->save($character);
        $retired = $this->aCharacterOf($this->person->id, 'Elrond', retired: true);
        $moderated = $this->aCharacterOf($this->person->id, 'Boromir', lockedByModeration: true);
        $others = $this->aCharacterOf($this->other->id, 'Gimli');

        $this->anonymize();

        $repository = app(CharacterRepositoryInterface::class);
        foreach ([$character, $retired, $moderated] as $mine) {
            $stored = $repository->findOrFail($mine->id());
            $this->assertNull($stored->description());
            $this->assertNull($stored->portraitPath());
            $this->assertTrue($stored->isRetired());
            $this->assertTrue($stored->isRetirementLocked());
        }
        $this->assertSame('Arwen', $repository->findOrFail($character->id())->name());
        $this->assertNotNull($repository->findOrFail($others->id())->description());
        $this->assertFalse($repository->findOrFail($others->id())->isRetired());

        try {
            $repository->findOrFail($character->id())->restore(new DateTimeImmutable, byModeration: false);
            $this->fail('The owner brought back a character of an anonymized account');
        } catch (InvalidCharacterException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_portrait_stops_counting_as_used_in_the_media_library(): void
    {
        $character = $this->aCharacterOf($this->person->id, 'Arwen');
        $character->changePortrait(self::PORTRAIT, new DateTimeImmutable);
        app(CharacterRepositoryInterface::class)->save($character);
        $file = app(MediaFileIdResolverInterface::class)->fromStoredPath(self::PORTRAIT);
        $this->assertInstanceOf(MediaFileId::class, $file);
        $source = app(CharacterPortraitMediaSource::class);
        $this->assertTrue($source->usesFile($file));

        $this->anonymize();

        $this->assertFalse($source->usesFile($file));
    }

    public function test_the_images_of_the_posts_stay(): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'forum/posts/2026/11/x.webp']]]];
        DB::table('forum_posts')->where('id', $this->personsPost->id()->value)->update(['body' => json_encode($document, JSON_UNESCAPED_SLASHES)]);

        $this->anonymize();

        $this->assertStringContainsString('forum/posts/2026/11/x.webp', (string) json_encode($this->postRow($this->personsPost)->body, JSON_UNESCAPED_SLASHES));
    }

    public function test_images_from_other_sites_stay_with_their_copy(): void
    {
        $copy = 'forum/external/2026/11/0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a.png';
        $document = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['origin' => 'external', 'src' => 'https://i.imgur.com/AbC123.png', 'path' => $copy]]]];
        DB::table('forum_posts')->where('id', $this->personsPost->id()->value)->update(['body' => json_encode($document, JSON_UNESCAPED_SLASHES)]);
        DB::table('forum_external_images')->insert(['id' => (string) Str::uuid(), 'url_hash' => hash('sha256', 'https://i.imgur.com/AbC123.png'), 'url' => 'https://i.imgur.com/AbC123.png', 'path' => $copy, 'fetched_at' => now()]);

        $this->anonymize();

        $this->assertStringContainsString($copy, (string) json_encode($this->postRow($this->personsPost)->body, JSON_UNESCAPED_SLASHES));
        // The copy holds nobody's data: it stays for the post that shows it
        $this->assertSame($copy, DB::table('forum_external_images')->value('path'));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedEngagement($this->person);
        $character = $this->aCharacterOf($this->person->id, 'Arwen');
        $this->anonymize();
        $before = (array) DB::table('forum_characters')->where('id', $character->id()->value)->first();

        $event = new UserAnonymized($this->person->id);
        event($event);
        event($event);

        $this->assertSame($before, (array) DB::table('forum_characters')->where('id', $character->id()->value)->first());
        foreach (ErasedTables::columns() as [$table, $column]) {
            $this->assertSame(0, DB::table($table)->where($column, $this->person->id)->count());
        }
    }

    public function test_a_failure_leaves_everything_as_it_was(): void
    {
        $this->seedEngagement($this->person);
        $character = $this->aCharacterOf($this->person->id, 'Arwen');
        // The characters are touched last: a failure there must give back the rows already gone
        CharacterModel::saving(static function (): never {
            throw new \RuntimeException('boom');
        });

        try {
            app(AccountErasure::class)->forget($this->person->id);
            $this->fail('The failure was swallowed');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        foreach (ErasedTables::columns() as [$table, $column]) {
            $this->assertSame(1, DB::table($table)->where($column, $this->person->id)->count(), "{$table}.{$column} was left half done");
        }
        $this->assertNotNull(app(CharacterRepositoryInterface::class)->findOrFail($character->id())->description());
    }

    public function test_the_system_account_is_never_forgotten_by_the_forum(): void
    {
        $system = app(SystemAccountInterface::class);
        $systemId = $system->id();
        $this->seedEngagement(UserModel::query()->findOrFail($systemId));
        $character = $this->aCharacterOf($systemId, 'Voz del sitio');

        app(AccountErasure::class)->forget($systemId);

        foreach (ErasedTables::columns() as [$table, $column]) {
            $this->assertSame(1, DB::table($table)->where($column, $systemId)->count());
        }
        $this->assertFalse(app(CharacterRepositoryInterface::class)->findOrFail($character->id())->isRetired());
    }

    public function test_when_the_core_anonymizes_the_system_account_the_forum_makes_a_new_one(): void
    {
        $oldId = app(SystemAccountInterface::class)->id();

        app(UserServiceInterface::class)->anonymize($oldId);

        $newId = app(SystemAccountInterface::class)->id();
        $this->assertNotSame($oldId, $newId);
        $this->assertTrue(app(SystemAccountInterface::class)->isSystemAccount($newId));
    }

    private function anonymize(): void
    {
        app(UserServiceInterface::class)->anonymize($this->person->id);
    }

    private function postRow(Post $post): PostModel
    {
        return PostModel::query()->findOrFail($post->id()->value);
    }

    private function othersPostBody(): PostBody
    {
        $post = app(PostRepositoryInterface::class)->find($this->othersPost->id());
        $this->assertInstanceOf(Post::class, $post);

        return $post->body();
    }

    /** One row of each table that holds what only matters to the person */
    private function seedEngagement(UserModel $user): void
    {
        $id = $user->id;
        DB::table('forum_post_likes')->insert(['post_id' => $this->othersPost->id()->value, 'user_id' => $id, 'created_at' => '2026-11-11 10:00:00']);
        DB::table('forum_discussion_follows')->insert(['user_id' => $id, 'discussion_id' => $this->discussion->id()->value, 'level' => 'following', 'updated_at' => '2026-11-11 10:00:00']);
        DB::table('forum_category_follows')->insert(['user_id' => $id, 'category_id' => $this->discussion->categoryId()->value, 'origin' => 'person', 'created_at' => '2026-11-11 10:00:00']);
        DB::table('forum_user_preferences')->insert(['user_id' => $id, 'auto_follow' => false, 'updated_at' => '2026-11-11 10:00:00']);
        DB::table('forum_discussion_reads')->insert(['user_id' => $id, 'discussion_id' => $this->discussion->id()->value, 'last_read_number' => 2, 'read_at' => '2026-11-11 10:00:00']);
        DB::table('forum_category_reads')->insert(['user_id' => $id, 'category_id' => $this->discussion->categoryId()->value, 'marked_read_at' => '2026-11-11 10:00:00']);
        DB::table('forum_reports')->insert([
            'id' => (string) Str::uuid(), 'post_id' => $this->othersPost->id()->value, 'reporter_id' => $id,
            'reason' => 'spam', 'created_at' => '2026-11-11 10:00:00',
        ]);

        // Private conversations (forum#18): one with someone else, a start, blocks both ways and a report
        $someone = UserModel::factory()->create();
        $conversation = (string) Str::uuid();
        DB::table('forum_conversations')->insert(['id' => $conversation, 'title' => 'Privada', 'last_number' => 1, 'last_message_number' => 1, 'last_activity_at' => '2026-11-11 10:00:00', 'created_at' => '2026-11-11 10:00:00', 'updated_at' => '2026-11-11 10:00:00']);
        foreach ([$id, $someone->id] as $participant) {
            DB::table('forum_conversation_participants')->insert(['conversation_id' => $conversation, 'user_id' => $participant, 'read_through' => 1, 'joined_at' => '2026-11-11 10:00:00']);
        }
        DB::table('forum_conversation_starts')->insert(['id' => (string) Str::uuid(), 'user_id' => $id, 'created_at' => '2026-11-11 10:00:00']);
        DB::table('forum_user_blocks')->insert(['blocker_user_id' => $id, 'blocked_user_id' => $someone->id, 'created_at' => '2026-11-11 10:00:00']);
        DB::table('forum_user_blocks')->insert(['blocker_user_id' => $someone->id, 'blocked_user_id' => $id, 'created_at' => '2026-11-11 10:00:00']);
        DB::table('forum_conversation_reports')->insert(['id' => (string) Str::uuid(), 'reporter_id' => $id, 'reason' => 'spam', 'context' => '[]', 'created_at' => '2026-11-11 10:00:00']);
    }
}
