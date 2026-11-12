<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Security\Services\ContentSecurityPolicySourcesInterface;
use Illuminate\Support\Facades\Route;
use Modules\Forum\Application\Embeds\EmbedProviders;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The pages may frame the players of the enabled services, and only those: the forum gives the
 * core's Content Security Policy their origins, read from the settings every time the header is
 * built, so turning a service off takes it out without deploying.
 */
final class EmbedFrameSourcesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/forum-test-csp', fn () => response('ok'))->middleware('web');
    }

    public function test_every_enabled_service_player_may_be_framed(): void
    {
        $expected = [];
        foreach (EmbedProviders::builtIn()->all() as $provider) {
            $expected = [...$expected, ...$provider->frameOrigins()];
        }
        sort($expected);

        $this->assertSame($expected, $this->app->make(ContentSecurityPolicySourcesInterface::class)->frameOrigins());
        $this->assertStringContainsString(
            "frame-src 'self' ".implode(' ', $expected).';',
            (string) $this->get('/forum-test-csp')->headers->get('Content-Security-Policy'),
        );
    }

    public function test_a_service_turned_off_leaves_the_policy_on_the_next_request(): void
    {
        $this->assertStringContainsString('https://www.youtube-nocookie.com', $this->framesOf($this->get('/forum-test-csp')->headers->get('Content-Security-Policy')));

        config(['modules.settings.forum.embed_services' => ['vimeo', 'twitch']]);

        $this->assertSame(
            "frame-src 'self' https://clips.twitch.tv https://player.twitch.tv https://player.vimeo.com",
            $this->framesOf($this->get('/forum-test-csp')->headers->get('Content-Security-Policy')),
        );
    }

    public function test_no_service_on_means_no_other_site_may_be_framed(): void
    {
        config(['modules.settings.forum.embed_services' => []]);

        $this->assertSame("frame-src 'self'", $this->framesOf($this->get('/forum-test-csp')->headers->get('Content-Security-Policy')));
    }

    private function framesOf(?string $policy): string
    {
        foreach (explode(';', (string) $policy) as $directive) {
            if (str_starts_with(trim($directive), 'frame-src')) {
                return trim($directive);
            }
        }

        return '';
    }
}
