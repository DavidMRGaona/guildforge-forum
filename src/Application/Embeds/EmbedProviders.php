<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds;

use LogicException;
use Modules\Forum\Application\Embeds\Contracts\EmbedProviderInterface;
use Modules\Forum\Application\Embeds\Providers\BlueskyProvider;
use Modules\Forum\Application\Embeds\Providers\InstagramProvider;
use Modules\Forum\Application\Embeds\Providers\RedditProvider;
use Modules\Forum\Application\Embeds\Providers\TikTokProvider;
use Modules\Forum\Application\Embeds\Providers\TwitchProvider;
use Modules\Forum\Application\Embeds\Providers\VimeoProvider;
use Modules\Forum\Application\Embeds\Providers\XProvider;
use Modules\Forum\Application\Embeds\Providers\YouTubeProvider;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * The closed list of services posts may embed. A new one is a provider class added to
 * builtIn(); the forum settings turn each on or off.
 */
final readonly class EmbedProviders
{
    /** @var array<string, EmbedProviderInterface> */
    private array $providers;

    /**
     * @param  iterable<EmbedProviderInterface>  $providers
     *
     * @throws LogicException when two share a key, or a key is not lowercase letters
     */
    public function __construct(iterable $providers)
    {
        $byKey = [];
        foreach ($providers as $provider) {
            if (preg_match('/^[a-z]{1,20}$/', $provider->key()) !== 1 || isset($byKey[$provider->key()])) {
                throw new LogicException("The embed service key '{$provider->key()}' is taken or not lowercase letters.");
            }
            $byKey[$provider->key()] = $provider;
        }
        $this->providers = $byKey;
    }

    public static function builtIn(): self
    {
        return new self([
            new RedditProvider,
            new XProvider,
            new BlueskyProvider,
            new InstagramProvider,
            new TikTokProvider,
            new YouTubeProvider,
            new VimeoProvider,
            new TwitchProvider,
        ]);
    }

    public function get(string $key): ?EmbedProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @return list<EmbedProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * The service and post a link points to, among the given services.
     *
     * @param  list<string>|null  $keys  the services to look in; null for all
     * @return array{EmbedProviderInterface, string}|null
     */
    public function resolve(RemoteUrl $url, ?array $keys = null): ?array
    {
        foreach ($this->providers as $key => $provider) {
            if ($keys !== null && ! in_array($key, $keys, true)) {
                continue;
            }
            $id = $provider->idFromUrl($url);
            if ($id !== null) {
                return [$provider, $id];
            }
        }

        return null;
    }
}
