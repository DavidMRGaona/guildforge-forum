<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\Realtime\Services\RealtimePublisherInterface;
use App\Application\Services\UserServiceInterface;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Search\Index\NullSearchIndexWriter;
use App\Infrastructure\Search\Index\SearchIndexWriterInterface;
use App\Infrastructure\Search\Persistence\Eloquent\Models\SearchDocumentModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Contracts\MentionTextsInterface;
use Modules\Forum\Application\Publications\PublicationFacts;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\HiddenRolls;
use Modules\Forum\Domain\Dice\NumberGenerator;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\SubjectDiscussion;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiceRollModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostRevisionModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use Modules\Forum\Tests\Support\RecordingRealtimePublisher;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Hidden rolls (forum#83): the result of a hidden roll reaches only whoever threw it, whoever
 * moderates the discussion and, in a campaign's space, its direction; everyone else reads
 * «Tirada oculta: 2d6+3 Ataque». It never leaks by any other way: the post's HTML and JSON, its
 * stored plain text (mail, notices, mentions), the search index, quotations, old versions, live
 * updates and the conversation on a record's page. Whoever threw it or the direction reveal it,
 * once and for good.
 *
 * Every die is a 4 and then a 2, so «2d6+3» always totals 9.
 */
final class HiddenRollsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string CAMPAIGN = 'c-1';

    private const string RESULT = '<strong>9</strong>';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $thrower;

    private UserModel $reader;

    private UserModel $director;

    private UserModel $moderator;

    private Category $general;

    private Category $space;

    private Discussion $generalDiscussion;

    private Discussion $spaceDiscussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->app->instance(SearchIndexWriterInterface::class, new NullSearchIndexWriter);
        $this->app->instance(NumberGenerator::class, new FixedNumbers(array_merge(...array_fill(0, 40, [4, 2]))));

        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();
        app(PublicationSourceRegistryInterface::class)->register(FakeCampaignSource::class, 'example');
        app(PublicationSourceRegistryInterface::class)->register(FakeGameTableSource::class, 'example');

        $this->thrower = UserModel::factory()->create(['name' => 'Ana', 'display_name' => null]);
        $this->reader = UserModel::factory()->create(['name' => 'Luis', 'display_name' => null]);
        $this->director = UserModel::factory()->create(['name' => 'Marta', 'display_name' => null]);
        $this->moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);

        FakePublicationSource::put(new PublicationDTO(
            type: FakeCampaignSource::TYPE,
            id: self::CAMPAIGN,
            title: 'La tumba',
            summary: 'Campaña de prueba.',
            url: 'https://example.test/campanas/la-tumba',
            state: PublicationState::Active,
        ));
        FakeCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO(
            [strtolower($this->thrower->id), strtolower($this->reader->id)],
            [strtolower($this->director->id)],
        );

        $this->general = $this->aCategory('general');
        $campaigns = Category::system(CategoryId::generate(), SystemCategory::Campaigns, 'Campañas', CategorySlug::fromName('Campañas'), 1);
        app(CategoryRepositoryInterface::class)->save($campaigns);
        // A campaign's space as the forum makes it: its direction moderates it
        $this->space = new Category(
            CategoryId::generate(),
            'La tumba',
            new CategorySlug('la-tumba'),
            position: 2,
            parentId: $campaigns->id(),
            viewAudience: CategoryAudience::everyone(),
            writeAudience: CategoryAudience::signedIn(),
            moderators: new CategoryModerators([], RosterScope::Leaders),
            subject: new CategorySubject(FakeCampaignSource::TYPE, self::CAMPAIGN),
        );
        app(CategoryRepositoryInterface::class)->save($this->space);

        [$this->generalDiscussion] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', title: 'Taberna');
        [$this->spaceDiscussion] = $this->aDiscussion($this->space, '2026-11-10 11:00:00', title: 'Diario de campaña');
    }

    protected function tearDown(): void
    {
        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();

        parent::tearDown();
    }

    // Who sees the result

    public function test_outside_a_campaign_only_whoever_threw_it_and_moderation_see_the_result(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        $this->assertSeesResult($this->thrower, $this->generalDiscussion);
        $this->assertSeesResult($this->moderator, $this->generalDiscussion);
        // The direction of a campaign is nobody special outside its space
        $this->assertWithheld($this->pageAs($this->director, $this->generalDiscussion));
        $this->assertWithheld($this->pageAs($this->reader, $this->generalDiscussion));
        $this->assertWithheld($this->pageAs(null, $this->generalDiscussion));
    }

    public function test_others_read_the_expression_and_the_reason_but_neither_dice_nor_total(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        $html = $this->postHtml($this->pageAs($this->reader, $this->generalDiscussion));

        $this->assertStringContainsString('Tirada oculta:', $html);
        $this->assertStringContainsString('<code class="forum-roll__expression">2d6+3</code>', $html);
        $this->assertStringContainsString('<span class="forum-roll__reason">Ataque</span>', $html);
        $this->assertStringContainsString('forum-roll--hidden', $html);
        $this->assertStringNotContainsString('forum-roll__die', $html);
        $this->assertStringNotContainsString('forum-roll__total', $html);
    }

    public function test_whoever_sees_it_is_told_it_is_hidden_and_who_else_sees_it(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        $html = $this->postHtml($this->pageAs($this->thrower, $this->generalDiscussion));

        $this->assertStringContainsString('forum-roll--secret', $html);
        $this->assertStringContainsString('Solo ven el resultado quien tiró y la moderación.', $html);
        $this->assertStringContainsString('data-roll-reveal="', $html);
        // Moderation sees it, but only who threw it (or the direction) reveals it
        $this->assertStringNotContainsString('data-roll-reveal="', $this->postHtml($this->pageAs($this->moderator, $this->generalDiscussion)));
    }

    public function test_in_a_campaign_space_its_direction_sees_the_result_and_the_other_players_do_not(): void
    {
        $this->hiddenReply($this->spaceDiscussion)->assertCreated();

        $this->assertSeesResult($this->thrower, $this->spaceDiscussion);
        $this->assertSeesResult($this->director, $this->spaceDiscussion);
        $this->assertSeesResult($this->moderator, $this->spaceDiscussion);
        $this->assertWithheld($this->pageAs($this->reader, $this->spaceDiscussion));
        $this->assertWithheld($this->pageAs(null, $this->spaceDiscussion));
        $this->assertStringContainsString('la dirección de la campaña', $this->postHtml($this->pageAs($this->director, $this->spaceDiscussion)));
    }

    public function test_without_the_campaign_roster_the_direction_sees_nothing_and_hidden_rolls_still_work(): void
    {
        $this->hiddenReply($this->spaceDiscussion)->assertCreated();
        FakeCampaignSource::$rosterFails = true;
        $this->app->forgetScopedInstances();

        $this->assertWithheld($this->pageAs($this->director, $this->spaceDiscussion));
        $this->assertSeesResult($this->thrower, $this->spaceDiscussion);
        $this->assertSeesResult($this->moderator, $this->spaceDiscussion);
    }

    public function test_a_rule_that_fails_keeps_the_result_from_the_reader_and_lets_nobody_reveal(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();
        $roll = app(DiceRollRepositoryInterface::class)->findMany([DiceRollModel::query()->sole()->id])[0];
        $failing = new class implements ForumAccessInterface
        {
            public function canRead(?Authenticatable $user, Discussion $discussion): bool
            {
                throw new RuntimeException('Forum rules unavailable');
            }

            public function canWrite(?Authenticatable $user, Discussion $discussion): bool
            {
                throw new RuntimeException('Forum rules unavailable');
            }

            public function canModerate(?Authenticatable $user, Discussion $discussion): bool
            {
                throw new RuntimeException('Forum rules unavailable');
            }
        };
        $rules = $this->app->make(HiddenRolls::class, ['access' => $failing]);

        $this->assertFalse($rules->maySee($this->moderator, $roll));
        $this->assertFalse($rules->mayReveal($this->thrower, $roll));
        // Whoever threw it needs no rule to see their own roll
        $this->assertTrue($rules->maySee($this->thrower, $roll));
        $this->assertTrue($rules->viewsFor([$roll], $this->moderator)[$roll->id]->withheld);
    }

    public function test_an_old_roll_and_a_public_one_are_shown_to_everyone(): void
    {
        $this->reply($this->generalDiscussion, [$this->roll('2d6+3', 'Ataque', hidden: false)])->assertCreated();

        $this->assertSeesResult($this->reader, $this->generalDiscussion);
        $this->assertFalse((bool) DiceRollModel::query()->value('is_hidden'));
    }

    // What is stored

    public function test_the_roll_is_stored_hidden_with_whoever_threw_it(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        $stored = DiceRollModel::query()->sole();
        $this->assertTrue($stored->is_hidden);
        $this->assertSame(strtolower($this->thrower->id), $stored->rolled_by);
        $this->assertSame(9, $stored->total, 'It is thrown and kept like any other roll.');
        $this->assertNull($stored->revealed_at);

        $node = $this->replyRow()->body['content'][0];
        $this->assertTrue($node['attrs']['hidden']);
        $this->assertSame($stored->id, $node['attrs']['rollId']);
    }

    public function test_the_stored_plain_text_never_holds_the_result(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        $this->assertSame('Tirada oculta: 2d6+3 Ataque', $this->replyRow()->plain_text);
    }

    public function test_an_edit_never_turns_a_hidden_roll_public_nor_a_public_one_hidden(): void
    {
        $postId = $this->hiddenReply($this->generalDiscussion)->json('post.id');
        $hidden = DiceRollModel::query()->sole()->id;
        $public = $this->reply($this->generalDiscussion, [$this->roll('1d6', null, hidden: false)])->json('post.id');
        $publicRoll = DiceRollModel::query()->where('post_id', $public)->sole()->id;

        $this->actingAs($this->thrower)
            ->patchJson("/foro/mensajes/{$postId}", ['document' => $this->doc([$this->roll('2d6+3', 'Golpe', rollId: $hidden, hidden: false)])])
            ->assertOk();
        $this->actingAs($this->thrower)
            ->patchJson("/foro/mensajes/{$public}", ['document' => $this->doc([$this->roll('1d6', null, rollId: $publicRoll, hidden: true)])])
            ->assertOk();

        $this->assertTrue((bool) DiceRollModel::query()->whereKey($hidden)->value('is_hidden'));
        $this->assertFalse((bool) DiceRollModel::query()->whereKey($publicRoll)->value('is_hidden'));
        $this->assertSame('Tirada oculta: 2d6+3 Golpe', (string) PostModel::query()->whereKey($postId)->value('plain_text'));
        $this->assertWithheld($this->postHtml($this->pageAs($this->reader, $this->generalDiscussion)));
    }

    public function test_a_hidden_roll_a_moderator_adds_by_editing_is_theirs_not_the_authors(): void
    {
        $postId = $this->reply($this->generalDiscussion, [$this->paragraph('Abro la puerta')])->json('post.id');

        $this->actingAs($this->moderator)
            ->patchJson("/foro/mensajes/{$postId}", ['document' => $this->doc([$this->paragraph('Abro la puerta'), $this->roll('2d6+3', 'Trampa')])])
            ->assertOk();

        $this->assertSame(strtolower((string) $this->moderator->id), DiceRollModel::query()->sole()->rolled_by);
        $this->assertWithheld($this->pageAs($this->thrower, $this->generalDiscussion));
    }

    // Every way out

    public function test_the_json_of_the_post_never_carries_the_result(): void
    {
        $response = $this->hiddenReply($this->generalDiscussion, as: $this->thrower)->assertCreated();
        // Whoever threw it is answered with it
        $this->assertStringContainsString(self::RESULT, (string) $response->json('post.html'));

        $props = $this->propsOf($this->pageAs($this->reader, $this->generalDiscussion));
        $this->assertWithheld($props);
        $this->assertStringNotContainsString('"total"', $props);
        $this->assertStringNotContainsString('"result"', $props);
    }

    public function test_the_quote_text_and_quotations_never_carry_the_result(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();
        $post = $this->propsArray($this->pageAs($this->reader, $this->generalDiscussion))['posts'][1];

        $this->assertSame('Tirada oculta: 2d6+3 Ataque', $post['quoteText']);
        $this->quote($post['id'], $post['quoteText'])->assertCreated();
        // A quotation that puts the result in is not of that post: refused
        $this->quote($post['id'], '2d6+3 Ataque = 9')->assertUnprocessable()->assertJsonPath('reason', 'invalid_quote');
    }

    public function test_the_search_index_never_holds_the_result(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        $bodies = SearchDocumentModel::query()->where('module', 'forum')->pluck('body')->implode("\n");
        $this->assertStringContainsString('Tirada oculta: 2d6+3 Ataque', $bodies);
        $this->assertStringNotContainsString('= 9', $bodies);

        $this->actingAs($this->reader)->get('/foro/buscar?q=Ataque')->assertOk()->assertDontSee('= 9', false);
    }

    public function test_the_index_and_the_stored_text_hold_the_result_only_once_the_public_text_does(): void
    {
        $postId = $this->hiddenReply($this->generalDiscussion)->json('post.id');

        // Before revealing: neither the stored text nor any search document has it
        $this->assertSame('Tirada oculta: 2d6+3 Ataque', $this->replyRow()->plain_text);
        foreach (SearchDocumentModel::query()->where('module', 'forum')->pluck('body') as $body) {
            $this->assertStringNotContainsString('= 9', (string) $body);
        }

        $this->reveal($this->thrower, DiceRollModel::query()->sole()->id)->assertOk();

        // After: the public text holds it, and the post's search document says exactly that
        $text = $this->replyRow()->plain_text;
        $this->assertSame('2d6+3 Ataque = 9', $text);
        $this->assertSame($text, (string) SearchDocumentModel::query()->where('module', 'forum')->where('source_id', $postId)->value('body'));
        foreach (SearchDocumentModel::query()->where('module', 'forum')->where('source_id', '!=', $postId)->pluck('body') as $body) {
            $this->assertStringNotContainsString('= 9', (string) $body, 'Only the revealed post says it.');
        }
    }

    public function test_notices_and_mails_never_carry_the_result(): void
    {
        $mentioned = UserModel::factory()->create();
        $mails = [];
        Event::listen(NotificationSent::class, static function (NotificationSent $event) use (&$mails): void {
            if ($event->channel === 'mail' && method_exists($event->notification, 'toMail')) {
                $message = $event->notification->toMail($event->notifiable);
                if ($message instanceof MailMessage) {
                    $mails[] = (string) $message->render();
                }
            }
        });

        $this->reply($this->generalDiscussion, [
            $this->roll('2d6+3', 'Ataque'),
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Mira, '], ['type' => 'mention', 'attrs' => ['id' => strtolower($mentioned->id)]]]],
        ])->assertCreated();

        $notices = app(SiteNotificationServiceInterface::class)->latest($mentioned, 5);
        $this->assertNotSame([], $notices);
        foreach ($notices as $notice) {
            $this->assertStringContainsString('Tirada oculta', (string) $notice->body);
            $this->assertStringNotContainsString('= 9', (string) $notice->body);
        }
        $this->assertNotSame([], $mails);
        foreach ($mails as $mail) {
            $this->assertStringNotContainsString('= 9', $mail);
        }
    }

    public function test_writing_mention_names_again_keeps_the_result_out(): void
    {
        $mentioned = UserModel::factory()->create(['name' => 'Pedro', 'display_name' => null]);
        $postId = $this->reply($this->generalDiscussion, [
            $this->roll('2d6+3', 'Ataque'),
            ['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => strtolower($mentioned->id)]]]],
        ])->json('post.id');

        // An account renamed or anonymized has its mentions written again
        $mentioned->forceFill(['name' => 'Pedro Gil'])->save();
        app(MentionTextsInterface::class)->refreshFor((string) $mentioned->id);

        $text = (string) PostModel::query()->whereKey($postId)->value('plain_text');
        $this->assertStringContainsString('Pedro Gil', $text);
        $this->assertStringContainsString('Tirada oculta: 2d6+3 Ataque', $text);
        $this->assertStringNotContainsString('= 9', $text);
    }

    public function test_old_versions_keep_the_result_from_everyone_but_moderation(): void
    {
        $postId = $this->hiddenReply($this->generalDiscussion)->json('post.id');
        $rollId = DiceRollModel::query()->sole()->id;
        $this->actingAs($this->thrower)
            ->patchJson("/foro/mensajes/{$postId}", ['document' => $this->doc([$this->roll('2d6+3', 'Golpe', rollId: $rollId)])])
            ->assertOk();

        $this->assertStringNotContainsString('= 9', (string) PostRevisionModel::query()->where('post_id', $postId)->value('plain_text'));
        // Versions are for moderation only, who sees the result (but reveals nothing from there)
        $this->actingAs($this->thrower)->getJson("/foro/mensajes/{$postId}/versiones")->assertForbidden();
        $versions = $this->actingAs($this->moderator)->getJson("/foro/mensajes/{$postId}/versiones")->assertOk();
        foreach ([0, 1] as $index) {
            $html = (string) $versions->json("data.{$index}.html");
            $this->assertStringContainsString(self::RESULT, $html);
            $this->assertStringNotContainsString('data-roll-reveal', $html);
        }
    }

    public function test_live_updates_never_carry_the_result_to_others(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();
        $url = "/foro/temas/{$this->generalDiscussion->id()->value}/novedades?despues=1";

        $this->assertWithheld((string) $this->actingAs($this->reader)->getJson($url)->assertOk()->getContent());
        $this->assertStringContainsString(self::RESULT, (string) $this->actingAs($this->thrower)->getJson($url)->assertOk()->json('posts.0.html'));
    }

    public function test_the_conversation_on_a_records_page_never_carries_the_result_to_others(): void
    {
        $tables = $this->aCategoryFor(CategoryAudience::everyone(), 'mesas');
        [$discussion, $opening] = $this->aDiscussion($tables, '2026-11-10 12:00:00', title: 'Partida del viernes');
        $facts = new PublicationFacts(PublicationFacts::PUBLISHED, PublicationState::Active, null, false, true, 'Partida del viernes', 'opening');
        $record = new SubjectDiscussion(new PublicationKey('game-table', 'table-1'));
        $record->announce($discussion->id(), $opening->id(), $facts->toArray(), $facts->isLive());
        app(SubjectDiscussionRepositoryInterface::class)->save($record);
        $this->hiddenReply($discussion)->assertCreated();
        config(['modules.settings.forum.subject_conversations' => ['game-table' => 'reply']]);

        $this->assertWithheld((string) $this->actingAs($this->reader)->getJson('/foro/fichas/game-table/table-1')->assertOk()->getContent());
        $this->assertStringContainsString(self::RESULT, (string) $this->actingAs($this->thrower)->getJson('/foro/fichas/game-table/table-1')->assertOk()->json('conversation.posts.0.html'));
    }

    // Revealing

    public function test_whoever_threw_it_reveals_it_for_everyone_and_for_good(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();
        $rollId = DiceRollModel::query()->sole()->id;
        $this->travelTo(new \DateTimeImmutable('2026-11-13 19:30:00 UTC'));

        $response = $this->reveal($this->thrower, $rollId)->assertOk();

        $this->assertStringContainsString(self::RESULT, (string) $response->json('post.html'));
        $stored = DiceRollModel::query()->sole();
        $this->assertSame(strtolower($this->thrower->id), $stored->revealed_by);
        $this->assertSame('2026-11-13 19:30:00', $stored->revealed_at?->format('Y-m-d H:i:s'));

        $this->assertSeesResult($this->reader, $this->generalDiscussion);
        $this->assertSeesResult(null, $this->generalDiscussion);
        $this->assertStringContainsString('revelada por Ana el', $this->postHtml($this->pageAs($this->reader, $this->generalDiscussion)));
        $this->assertSame('2d6+3 Ataque = 9', $this->replyRow()->plain_text);
        $this->assertNull($this->replyRow()->edited_at, 'Revealing is not an edit.');
        $this->assertSame(0, PostRevisionModel::query()->count());
        $this->assertStringContainsString('= 9', SearchDocumentModel::query()->where('module', 'forum')->pluck('body')->implode("\n"));

        // There is no way back, and nothing to reveal twice
        $this->reveal($this->thrower, $rollId)->assertStatus(409)->assertJsonPath('reason', 'already_revealed');
    }

    public function test_a_changed_post_asked_for_in_real_time_follows_the_hidden_rolls_rules_for_each_reader(): void
    {
        $realtime = new RecordingRealtimePublisher;
        $this->app->instance(RealtimePublisherInterface::class, $realtime);
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'example-key', 'broadcasting.connections.reverb.secret' => 'example-secret', 'broadcasting.connections.reverb.app_id' => 'example-app', 'broadcasting.connections.reverb.options.host' => 'realtime.internal']);
        $this->hiddenReply($this->generalDiscussion)->assertCreated();
        $changed = "/foro/temas/{$this->generalDiscussion->id()->value}/novedades?despues=2&cambiados=2";

        // Before revealing: the reader is told it is hidden, whoever threw it sees the result
        $this->assertWithheld((string) $this->actingAs($this->reader)->getJson($changed)->assertOk()->json('posts.0.html'));
        $this->assertStringContainsString(self::RESULT, (string) $this->actingAs($this->thrower)->getJson($changed)->assertOk()->json('posts.0.html'));

        $realtime->forget();
        $this->reveal($this->thrower, DiceRollModel::query()->sole()->id)->assertOk();

        self::assertSame(
            [['event' => 'post.changed', 'payload' => ['number' => 2]]],
            $realtime->on('forum.discussion.'.$this->generalDiscussion->id()->value),
        );
        $this->assertStringContainsString(self::RESULT, (string) $this->actingAs($this->reader)->getJson($changed)->assertOk()->json('posts.0.html'));
    }

    public function test_the_direction_reveals_a_hidden_roll_in_its_campaign_space(): void
    {
        $this->hiddenReply($this->spaceDiscussion)->assertCreated();

        $this->reveal($this->director, DiceRollModel::query()->sole()->id)->assertOk();

        $this->assertSame(strtolower($this->director->id), DiceRollModel::query()->sole()->revealed_by);
        $this->assertSeesResult($this->reader, $this->spaceDiscussion);
    }

    public function test_nobody_else_reveals_it(): void
    {
        $this->hiddenReply($this->spaceDiscussion)->assertCreated();
        $rollId = DiceRollModel::query()->sole()->id;

        $this->reveal($this->moderator, $rollId)->assertForbidden();
        $this->reveal($this->reader, $rollId)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson("/foro/tiradas/{$rollId}/revelar")->assertUnauthorized();
        $this->reveal($this->thrower, '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6')->assertNotFound();

        $this->assertNull(DiceRollModel::query()->sole()->revealed_at);
    }

    public function test_outside_a_campaign_the_direction_of_another_campaign_does_not_reveal(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        $this->reveal($this->director, DiceRollModel::query()->sole()->id)->assertForbidden();
    }

    public function test_a_public_roll_has_nothing_to_reveal(): void
    {
        $this->reply($this->generalDiscussion, [$this->roll('2d6+3', 'Ataque', hidden: false)])->assertCreated();

        $this->reveal($this->thrower, DiceRollModel::query()->sole()->id)->assertStatus(409)->assertJsonPath('reason', 'not_hidden');
    }

    public function test_anonymizing_who_threw_it_keeps_the_roll_and_its_secret(): void
    {
        $this->hiddenReply($this->generalDiscussion)->assertCreated();

        app(UserServiceInterface::class)->anonymize((string) $this->thrower->id);

        $this->assertSame(1, DiceRollModel::query()->count());
        $this->assertTrue(DiceRollModel::query()->sole()->is_hidden);
        $this->assertWithheld($this->pageAs($this->reader, $this->generalDiscussion));
    }

    // Helpers

    /**
     * @return TestResponse<Response>
     */
    private function hiddenReply(Discussion $discussion, ?UserModel $as = null): TestResponse
    {
        return $this->reply($discussion, [$this->roll('2d6+3', 'Ataque')], $as);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return TestResponse<Response>
     */
    private function reply(Discussion $discussion, array $content, ?UserModel $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->thrower)->postJson("/foro/temas/{$discussion->id()->value}/mensajes", ['document' => $this->doc($content)]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function reveal(UserModel $user, string $rollId): TestResponse
    {
        return $this->actingAs($user)->postJson("/foro/tiradas/{$rollId}/revelar");
    }

    /**
     * @return TestResponse<Response>
     */
    private function quote(string $postId, string $text): TestResponse
    {
        return $this->actingAs($this->reader)->postJson("/foro/temas/{$this->generalDiscussion->id()->value}/mensajes", ['document' => $this->doc([
            ['type' => 'postQuote', 'attrs' => ['postId' => $postId], 'content' => [$this->paragraph($text)]],
            $this->paragraph('Eso'),
        ])]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function pageAs(?UserModel $user, Discussion $discussion): TestResponse
    {
        $this->app->forgetScopedInstances();
        if ($user instanceof UserModel) {
            $this->actingAs($user);
        } else {
            $this->app['auth']->guard()->logout();
        }

        $stored = app(DiscussionRepositoryInterface::class)->find($discussion->id());
        $this->assertInstanceOf(Discussion::class, $stored);

        return $this->get(app(ForumUrlsInterface::class)->discussion($stored))->assertOk();
    }

    private function assertSeesResult(?UserModel $user, Discussion $discussion): void
    {
        $this->assertStringContainsString(self::RESULT, $this->postHtml($this->pageAs($user, $discussion)));
    }

    /**
     * @param  TestResponse<Response>|string  $where
     */
    private function assertWithheld(TestResponse|string $where): void
    {
        $text = is_string($where) ? $where : $this->propsOf($where);

        $this->assertStringContainsString('Tirada oculta', $text);
        $this->assertStringNotContainsString(self::RESULT, $text);
        $this->assertStringNotContainsString('forum-roll__die', $text);
        $this->assertStringNotContainsString('= 9', $text);
    }

    /**
     * @param  TestResponse<Response>  $page
     * @return array<string, mixed>
     */
    private function propsArray(TestResponse $page): array
    {
        $data = $page->viewData('page');
        $this->assertIsArray($data);
        $this->assertIsArray($data['props']);

        return $data['props'];
    }

    /**
     * @param  TestResponse<Response>  $page
     */
    private function propsOf(TestResponse $page): string
    {
        return json_encode($this->propsArray($page), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The HTML of the reply (post 2).
     *
     * @param  TestResponse<Response>  $page
     */
    private function postHtml(TestResponse $page): string
    {
        $posts = $this->propsArray($page)['posts'];
        $this->assertIsArray($posts);

        return (string) ($posts[1]['html'] ?? '');
    }

    private function replyRow(): PostModel
    {
        return PostModel::query()->where('number', 2)->where('discussion_id', $this->generalDiscussion->id()->value)->sole();
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function roll(string $expression, ?string $reason, ?string $rollId = null, bool $hidden = true): array
    {
        return ['type' => 'roll', 'attrs' => ['expression' => $expression, 'reason' => $reason, 'rollId' => $rollId, 'hidden' => $hidden]];
    }
}
