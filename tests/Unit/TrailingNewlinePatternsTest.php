<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Modules\Forum\Application\Content\Nodes\CodeBlockNode;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Content\Nodes\RollNode;
use Modules\Forum\Application\Embeds\Contracts\EmbedProviderInterface;
use Modules\Forum\Application\Embeds\EmbedProviders;
use Modules\Forum\Application\Embeds\Providers\BlueskyProvider;
use Modules\Forum\Application\Queries\DiscussionCursor;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Domain\ValueObjects\IpAddress;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use Modules\Forum\Infrastructure\Services\ForumSettings;
use Tests\TestCase;

/**
 * In PCRE `$` also matches before a final newline, so «valor\n» slipped through patterns that
 * promise a strict format. Each one of them ends in `\z`.
 */
final class TrailingNewlinePatternsTest extends TestCase
{
    private const string UUID = '0b8e2d3a-1f4c-4d6e-9a7b-3c5d7e9f1a2b';

    public function test_a_slug_refuses_a_final_newline(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategorySlug("general\n");
    }

    public function test_a_color_refuses_a_final_newline(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategoryAppearance("#aabbcc\n", null);
    }

    public function test_a_topic_key_refuses_a_final_newline(): void
    {
        $this->expectException(InvalidDiscussionException::class);

        new DiscussionTopic("dados\n", 'Dados');
    }

    public function test_a_publication_type_refuses_a_final_newline(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PublicationKey("game-table\n", 'abc');
    }

    public function test_a_category_subject_type_refuses_a_final_newline(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategorySubject("game-table\n", 'abc');
    }

    public function test_an_ip_address_refuses_a_final_newline(): void
    {
        $this->assertNull(IpAddress::fromString("192.168.1.1\n"));
        $this->assertNotNull(IpAddress::fromString('192.168.1.1'));
    }

    public function test_a_host_name_refuses_a_final_newline(): void
    {
        $this->assertTrue(RemoteUrl::isHostName('example.com'));
        $this->assertFalse(RemoteUrl::isHostName("example.com\n"));
    }

    public function test_a_remote_address_refuses_a_final_newline(): void
    {
        $this->assertNotNull(RemoteUrl::tryFrom('https://example.com/a.png'));
        $this->assertNull(RemoteUrl::tryFrom("https://example.com/a.png\n"));
    }

    public function test_a_roll_id_refuses_a_final_newline(): void
    {
        (new RollNode)->validateAttributes(['rollId' => self::UUID], '$');
        $this->expectException(InvalidDocumentException::class);

        (new RollNode)->validateAttributes(['rollId' => self::UUID."\n"], '$');
    }

    public function test_a_code_language_refuses_a_final_newline(): void
    {
        (new CodeBlockNode)->validateAttributes(['language' => 'php'], '$');
        $this->expectException(InvalidDocumentException::class);

        (new CodeBlockNode)->validateAttributes(['language' => "php\n"], '$');
    }

    public function test_an_uploaded_image_path_refuses_a_final_newline(): void
    {
        $path = 'forum/posts/2026/11/'.self::UUID.'.png';

        $this->assertSame($path, ImageNode::storedPath(['origin' => ImageNode::ORIGIN_UPLOAD, 'src' => $path]));
        $this->assertNull(ImageNode::storedPath(['origin' => ImageNode::ORIGIN_UPLOAD, 'src' => $path."\n"]));
    }

    public function test_the_copy_of_an_external_image_refuses_a_final_newline(): void
    {
        $path = 'forum/external/2026/11/'.self::UUID.'.png';

        $this->assertSame($path, ImageNode::storedPath(['origin' => ImageNode::ORIGIN_EXTERNAL, 'path' => $path]));
        $this->assertNull(ImageNode::storedPath(['origin' => ImageNode::ORIGIN_EXTERNAL, 'path' => $path."\n"]));
    }

    public function test_a_portrait_path_refuses_a_final_newline(): void
    {
        $character = Character::create(CharacterId::generate(), self::UUID, 'Arwen', null, null, new DateTimeImmutable('2026-11-12 09:00:00'));
        $character->changePortrait('forum/characters/2026/11/'.self::UUID.'.png', new DateTimeImmutable('2026-11-12 10:00:00'));

        $this->expectException(InvalidCharacterException::class);

        $character->changePortrait('forum/characters/2026/11/'.self::UUID.".png\n", new DateTimeImmutable('2026-11-12 11:00:00'));
    }

    public function test_a_bluesky_did_refuses_a_final_newline(): void
    {
        $this->assertSame(1, preg_match(BlueskyProvider::DID_PATTERN, 'did:plc:abcdefghijklmnopqrstuvwx'));
        $this->assertSame(0, preg_match(BlueskyProvider::DID_PATTERN, "did:plc:abcdefghijklmnopqrstuvwx\n"));
    }

    public function test_every_embed_id_refuses_a_final_newline(): void
    {
        $ids = [
            'youtube' => 'dQw4w9WgXcQ',
            'vimeo' => '123456',
            'x' => '123456',
            'tiktok' => 'user.name/123456',
            'instagram' => 'p/ABCDE12345',
            'reddit' => 'sub_name/abc123',
            'twitch' => 'v:123',
        ];

        foreach (EmbedProviders::builtIn()->all() as $provider) {
            $id = $ids[$provider->key()] ?? null;
            if ($id === null) {
                continue;
            }
            $this->assertTrue($provider->isValidId($id), $provider->key());
            $this->assertFalse($provider->isValidId($id."\n"), $provider->key());
        }
    }

    public function test_an_embed_provider_key_refuses_a_final_newline(): void
    {
        $provider = $this->createStub(EmbedProviderInterface::class);
        $provider->method('key')->willReturn("vimeo\n");

        $this->expectException(LogicException::class);

        new EmbedProviders([$provider]);
    }

    public function test_a_cursor_refuses_a_final_newline_in_its_date_or_id(): void
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        $this->assertNotNull(DiscussionCursor::decode($encode(['2026-11-10 10:00:00', self::UUID])));
        $this->assertNull(DiscussionCursor::decode($encode(["2026-11-10 10:00:00\n", self::UUID])));
        $this->assertNull(DiscussionCursor::decode($encode(['2026-11-10 10:00:00', self::UUID."\n"])));
    }

    public function test_a_cookie_category_refuses_a_final_newline(): void
    {
        config(['modules.settings.forum.embed_cookie_categories.youtube' => "videos\n"]);
        $this->assertSame(ForumSettings::DEFAULT_EMBED_COOKIE_CATEGORY, (new ForumSettings)->embedCookieCategory('youtube'));

        config(['modules.settings.forum.embed_cookie_categories.youtube' => 'videos']);
        $this->assertSame('videos', (new ForumSettings)->embedCookieCategory('youtube'));
    }

    public function test_announced_categories_refuse_an_id_with_a_final_newline(): void
    {
        config(['modules.settings.forum.announced_categories' => [self::UUID."\n", self::UUID]]);

        $this->assertSame([self::UUID], (new ForumSettings)->announcedCategoryIds());
    }
}
