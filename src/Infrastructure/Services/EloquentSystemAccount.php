<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\SettingsServiceInterface;
use App\Application\Services\SiteBrandingServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\SystemAccountInterface;

/**
 * A local account with the site's name that the forum writes with.
 *
 * - It is given no role (not even the base one) and a random password nobody knows, and its
 *   address is a random one on a reserved domain (.invalid) that never receives mail, so nobody
 *   signs in with it.
 * - It is known only by the id kept in the settings, never by its address: an account somebody
 *   registered is never taken for it.
 * - Deleted or anonymized from the panel, a new one is created the next time it is needed.
 * - Its name follows the site's name.
 */
final readonly class EloquentSystemAccount implements SystemAccountInterface
{
    public const string SETTING = 'forum_system_account_id';

    public const string EMAIL_DOMAIN = 'system.invalid';

    private const string LOCK = 'forum-system-account';

    public function __construct(
        private SettingsServiceInterface $settings,
        private SiteBrandingServiceInterface $branding,
    ) {}

    public function id(): string
    {
        $name = $this->branding->siteName();
        $account = $this->stored() ?? $this->create($name);

        if ($account->name !== $name || $account->display_name !== $name) {
            $account->forceFill(['name' => $name, 'display_name' => $name])->saveQuietly();
        }

        return strtolower((string) $account->getKey());
    }

    public function isSystemAccount(?string $accountId): bool
    {
        $id = $this->settings->get(self::SETTING);

        return $accountId !== null && is_string($id) && $id !== '' && strtolower($accountId) === strtolower($id);
    }

    /**
     * The account the settings name, while it is there and not anonymized.
     */
    private function stored(): ?UserModel
    {
        $id = $this->settings->get(self::SETTING);
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return UserModel::query()->whereKey($id)->whereNull('anonymized_at')->first();
    }

    private function create(string $name): UserModel
    {
        // Two checks at once create one account: the second finds the first's
        return Cache::lock(self::LOCK, 10)->block(10, function () use ($name): UserModel {
            $this->settings->clearCache();
            $existing = $this->stored();
            if ($existing instanceof UserModel) {
                return $existing;
            }

            $id = (string) Str::uuid();
            $account = UserModel::query()->create([
                'id' => $id,
                'name' => $name,
                'display_name' => $name,
                'email' => "forum-system-{$id}@".self::EMAIL_DOMAIN,
                'password' => Str::random(64),
            ]);
            $this->settings->set(self::SETTING, $id);

            return $account;
        });
    }
}
