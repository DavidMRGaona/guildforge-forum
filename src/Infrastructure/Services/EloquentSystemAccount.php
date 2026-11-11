<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\SettingsServiceInterface;
use App\Application\Services\SiteBrandingServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\SystemAccountInterface;

/**
 * A local account with the site's name that the forum writes with. It is given no role (not even
 * the base one) and a random password nobody knows, and its address is on a reserved domain
 * (.invalid) that can never receive mail, so nobody signs in with it. Its id is kept in the
 * settings; should it be lost, the account is found again by its address. Its name follows the
 * site's name.
 */
final readonly class EloquentSystemAccount implements SystemAccountInterface
{
    public const string SETTING = 'forum_system_account_id';

    public const string EMAIL = 'forum-system@system.invalid';

    public function __construct(
        private SettingsServiceInterface $settings,
        private SiteBrandingServiceInterface $branding,
    ) {}

    public function id(): string
    {
        $name = $this->branding->siteName();
        $account = $this->stored() ?? UserModel::withTrashed()->where('email', self::EMAIL)->first() ?? $this->create($name);

        if ($account->name !== $name || $account->display_name !== $name) {
            $account->forceFill(['name' => $name, 'display_name' => $name])->saveQuietly();
        }

        $id = (string) $account->getKey();
        if ($this->settings->get(self::SETTING) !== $id) {
            $this->settings->set(self::SETTING, $id);
        }

        return $id;
    }

    private function stored(): ?UserModel
    {
        $id = $this->settings->get(self::SETTING);
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return UserModel::withTrashed()->whereKey($id)->first();
    }

    private function create(string $name): UserModel
    {
        try {
            return UserModel::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'display_name' => $name,
                'email' => self::EMAIL,
                'password' => Str::random(64),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Created by another check at the same time
            return UserModel::withTrashed()->where('email', self::EMAIL)->first() ?? throw $exception;
        }
    }
}
