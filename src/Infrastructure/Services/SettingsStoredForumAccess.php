<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Infrastructure\Modules\Services\SettingsTableModuleSettingsStore;
use Modules\Forum\Application\Contracts\StoredForumAccessInterface;
use Modules\Forum\Domain\Enums\ForumAccessMode;
use RuntimeException;
use Throwable;

/**
 * Reads the forum's stored settings row itself rather than through the module settings store,
 * which answers «nothing stored» when the row cannot be read: here that must throw, or a failure
 * would read as a preview and everything would stop counting.
 */
final readonly class SettingsStoredForumAccess implements StoredForumAccessInterface
{
    private const string MODULE = 'forum';

    public function __construct(
        private SettingsServiceInterface $settings,
        private ModuleSettingsStoreInterface $store,
    ) {}

    public function current(): ForumAccessMode
    {
        try {
            // From the table, never the cache: it may lag behind the save that queued this
            $stored = $this->settings->getStored(SettingsTableModuleSettingsStore::KEY_PREFIX.self::MODULE);
        } catch (Throwable $exception) {
            throw new RuntimeException('The stored forum settings could not be read.', 0, $exception);
        }

        $access = null;
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('The stored forum settings are not a JSON object.');
            }

            $access = $decoded['access'] ?? null;
        } elseif ($stored !== null && $stored !== '') {
            throw new RuntimeException('The stored forum settings are not a JSON object.');
        }

        // Never saved from the panel: the release default
        $access ??= $this->store->defaults(dirname(__DIR__, 3))['access'] ?? null;

        return is_string($access) ? (ForumAccessMode::tryFrom($access) ?? ForumAccessMode::Preview) : ForumAccessMode::Preview;
    }
}
