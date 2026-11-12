<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Application\Embeds;

use Carbon\CarbonImmutable;
use LogicException;
use Modules\Forum\Application\Embeds\EmbedPlayer;
use Modules\Forum\Application\Embeds\EmbedProviders;
use Modules\Forum\Application\Embeds\Providers\BlueskyProvider;
use Modules\Forum\Application\Embeds\Providers\YouTubeProvider;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each service reads its own links into an id it checks, and builds everything else (page,
 * oEmbed, player) from that id.
 */
final class EmbedProvidersTest extends TestCase
{
    private const string DID = 'did:plc:abcdefghijklmnopqrstuvwx';

    public function test_the_list_is_closed_and_in_order(): void
    {
        $this->assertSame(['reddit', 'x', 'bluesky', 'instagram', 'tiktok', 'youtube', 'vimeo', 'twitch'], EmbedProviders::builtIn()->keys());
    }

    public function test_two_services_cannot_share_a_key(): void
    {
        $this->expectException(LogicException::class);

        new EmbedProviders([new YouTubeProvider, new YouTubeProvider]);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function links(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'youtube short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'youtube shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'youtube mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo', '76979871', 'https://vimeo.com/76979871'];
        yield 'vimeo channel' => ['https://vimeo.com/channels/staffpicks/76979871', 'vimeo', '76979871', 'https://vimeo.com/76979871'];
        yield 'twitch video' => ['https://www.twitch.tv/videos/2245123456', 'twitch', 'v:2245123456', 'https://www.twitch.tv/videos/2245123456'];
        yield 'twitch clip' => ['https://clips.twitch.tv/AwkwardHelplessSalamanderSwiftRage', 'twitch', 'c:AwkwardHelplessSalamanderSwiftRage', 'https://clips.twitch.tv/AwkwardHelplessSalamanderSwiftRage'];
        yield 'twitch channel clip' => ['https://www.twitch.tv/rolero/clip/Funny-Slug_1', 'twitch', 'c:Funny-Slug_1', 'https://clips.twitch.tv/Funny-Slug_1'];
        yield 'twitch channel' => ['https://www.twitch.tv/RoleroLive', 'twitch', 'ch:rolerolive', 'https://www.twitch.tv/rolerolive'];
        yield 'reddit' => ['https://www.reddit.com/r/rpg/comments/1abc2de/my_campaign_map/', 'reddit', 'rpg/1abc2de', 'https://www.reddit.com/r/rpg/comments/1abc2de/'];
        yield 'old reddit' => ['https://old.reddit.com/r/DnD/comments/XYZ12/', 'reddit', 'DnD/xyz12', 'https://www.reddit.com/r/DnD/comments/xyz12/'];
        yield 'x' => ['https://x.com/someone/status/1790000000000000000', 'x', '1790000000000000000', 'https://x.com/i/status/1790000000000000000'];
        yield 'twitter' => ['https://twitter.com/someone/status/1790000000000000000?s=20', 'x', '1790000000000000000', 'https://x.com/i/status/1790000000000000000'];
        yield 'bluesky handle' => ['https://bsky.app/profile/Rolero.bsky.social/post/3kxyzabcdefgh', 'bluesky', 'rolero.bsky.social/3kxyzabcdefgh', 'https://bsky.app/profile/rolero.bsky.social/post/3kxyzabcdefgh'];
        yield 'bluesky did' => ['https://bsky.app/profile/'.self::DID.'/post/3kxyzabcdefgh', 'bluesky', self::DID.'/3kxyzabcdefgh', 'https://bsky.app/profile/'.self::DID.'/post/3kxyzabcdefgh'];
        yield 'instagram post' => ['https://www.instagram.com/p/C1a2B3c4D5e/', 'instagram', 'p/C1a2B3c4D5e', 'https://www.instagram.com/p/C1a2B3c4D5e/'];
        yield 'instagram reel' => ['https://instagram.com/reel/C1a2B3c4D5e/?igsh=x', 'instagram', 'reel/C1a2B3c4D5e', 'https://www.instagram.com/reel/C1a2B3c4D5e/'];
        yield 'tiktok' => ['https://www.tiktok.com/@rolero.es/video/7310000000000000000', 'tiktok', 'rolero.es/7310000000000000000', 'https://www.tiktok.com/@rolero.es/video/7310000000000000000'];
    }

    #[DataProvider('links')]
    public function test_a_link_gives_its_service_and_post(string $link, string $service, string $id, string $page): void
    {
        $resolved = EmbedProviders::builtIn()->resolve($this->url($link));

        $this->assertNotNull($resolved);
        [$provider, $found] = $resolved;
        $this->assertSame($service, $provider->key());
        $this->assertSame($id, $found);
        $this->assertTrue($provider->isValidId($id));
        $this->assertSame($page, $provider->url($id));
        $this->assertNotNull(RemoteUrl::tryFrom($provider->url($id)), 'The page is an address the forum could fetch');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPosts(): iterable
    {
        yield 'youtube channel' => ['https://www.youtube.com/@rolero'];
        yield 'youtube id too short' => ['https://www.youtube.com/watch?v=abc'];
        yield 'youtube list' => ['https://www.youtube.com/playlist?list=PL123'];
        yield 'vimeo home' => ['https://vimeo.com/'];
        yield 'twitch directory' => ['https://www.twitch.tv/directory'];
        yield 'reddit subreddit' => ['https://www.reddit.com/r/rpg/'];
        yield 'x profile' => ['https://x.com/someone'];
        yield 'bluesky profile' => ['https://bsky.app/profile/rolero.bsky.social'];
        yield 'instagram profile' => ['https://www.instagram.com/rolero/'];
        yield 'tiktok profile' => ['https://www.tiktok.com/@rolero'];
        yield 'another site' => ['https://example.com/watch?v=dQw4w9WgXcQ'];
        yield 'a site ending like one' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ'];
    }

    #[DataProvider('notPosts')]
    public function test_links_that_are_not_posts_give_nothing(string $link): void
    {
        $this->assertNull(EmbedProviders::builtIn()->resolve($this->url($link)));
    }

    public function test_only_the_given_services_are_looked_in(): void
    {
        $url = $this->url('https://youtu.be/dQw4w9WgXcQ');

        $this->assertNull(EmbedProviders::builtIn()->resolve($url, ['vimeo']));
        $this->assertNotNull(EmbedProviders::builtIn()->resolve($url, ['youtube']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forgedIds(): iterable
    {
        yield 'youtube with a path' => ['youtube', 'dQw4w9WgX/Q'];
        yield 'youtube with a query' => ['youtube', 'dQw4w9?x=1'];
        yield 'vimeo letters' => ['vimeo', '12a'];
        yield 'twitch unknown kind' => ['twitch', 'z:123'];
        yield 'twitch channel with slash' => ['twitch', 'ch:a/b'];
        yield 'reddit climbing' => ['reddit', '../x/abc'];
        yield 'x letters' => ['x', '12ab'];
        yield 'bluesky without key' => ['bluesky', 'rolero.bsky.social'];
        yield 'instagram other kind' => ['instagram', 'stories/abcdef'];
        yield 'tiktok without video' => ['tiktok', 'rolero'];
        yield 'empty' => ['youtube', ''];
    }

    #[DataProvider('forgedIds')]
    public function test_ids_are_checked_by_each_service(string $service, string $id): void
    {
        $this->assertFalse(EmbedProviders::builtIn()->get($service)?->isValidId($id));
    }

    public function test_players_are_https_on_the_service_frame_origins(): void
    {
        $samples = ['reddit' => 'rpg/1abc2de', 'x' => '1790000000000000000', 'bluesky' => self::DID.'/3kxyzabcdefgh', 'instagram' => 'p/C1a2B3c4D5e', 'tiktok' => 'rolero.es/7310000000000000000', 'youtube' => 'dQw4w9WgXcQ', 'vimeo' => '76979871', 'twitch' => 'v:2245123456'];

        foreach (EmbedProviders::builtIn()->all() as $provider) {
            $player = $provider->player($samples[$provider->key()], null, 'foro.example.org');
            $this->assertInstanceOf(EmbedPlayer::class, $player, $provider->key());
            $origin = parse_url($player->src, PHP_URL_SCHEME).'://'.parse_url($player->src, PHP_URL_HOST);
            $this->assertContains($origin, $provider->frameOrigins(), $provider->key());
            $this->assertStringStartsWith('https://', $player->src);
        }
    }

    public function test_youtube_plays_without_cookies_and_twitch_knows_the_site(): void
    {
        $providers = EmbedProviders::builtIn();

        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0', $providers->get('youtube')?->player('dQw4w9WgXcQ', null, 'x')?->src);
        $this->assertSame('https://player.twitch.tv/?video=v2245123456&parent=foro.example.org&autoplay=false', $providers->get('twitch')?->player('v:2245123456', null, 'foro.example.org')?->src);
        $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1', $providers->get('vimeo')?->player('76979871', null, 'x')?->src);
    }

    public function test_a_bluesky_handle_needs_the_did_from_its_card_to_play(): void
    {
        $bluesky = new BlueskyProvider;
        $id = 'rolero.bsky.social/3kxyzabcdefgh';

        $this->assertNull($bluesky->player($id, null, 'x'));
        $card = new EmbedCard('bluesky', $id, true, null, 'Rolero', null, [BlueskyProvider::DID => self::DID], CarbonImmutable::now());
        $this->assertSame('https://embed.bsky.app/embed/'.self::DID.'/app.bsky.feed.post/3kxyzabcdefgh', $bluesky->player($id, $card, 'x')?->src);
        // A forged detail is never used
        $forged = new EmbedCard('bluesky', $id, true, null, null, null, [BlueskyProvider::DID => 'did:web:evil.example/../x'], CarbonImmutable::now());
        $this->assertNull($bluesky->player($id, $forged, 'x'));
    }

    public function test_oembed_addresses_carry_the_page(): void
    {
        $this->assertSame(
            'https://www.youtube.com/oembed?format=json&url=https%3A%2F%2Fwww.youtube.com%2Fwatch%3Fv%3DdQw4w9WgXcQ',
            EmbedProviders::builtIn()->get('youtube')?->oembedUrl('dQw4w9WgXcQ')?->value,
        );
        $this->assertNull(EmbedProviders::builtIn()->get('instagram')?->oembedUrl('p/C1a2B3c4D5e'));
        $this->assertNull(EmbedProviders::builtIn()->get('twitch')?->oembedUrl('v:1'));
    }

    private function url(string $value): RemoteUrl
    {
        $url = RemoteUrl::tryFrom($value);
        $this->assertInstanceOf(RemoteUrl::class, $url, $value);

        return $url;
    }
}
