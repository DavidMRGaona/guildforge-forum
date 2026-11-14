<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The profile avatars the forum shows next to posts, read from the account each time: only
 * active accounts (neither deactivated nor anonymized) with an avatar.
 */
final class AccountAvatarsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_it_returns_the_avatar_of_an_active_account(): void
    {
        $user = $this->anAccount('avatars/ana');

        $this->assertSame([strtolower($user->id) => 'avatars/ana'], $this->directory()->avatarsOf([$user->id]));
    }

    public function test_it_leaves_out_accounts_without_avatar(): void
    {
        $none = $this->anAccount(null);
        $blank = $this->anAccount('');

        $this->assertSame([], $this->directory()->avatarsOf([$none->id, $blank->id]));
    }

    public function test_it_leaves_out_deactivated_accounts(): void
    {
        $user = $this->anAccount('avatars/luis');
        $user->delete();

        $this->assertSame([], $this->directory()->avatarsOf([$user->id]));
    }

    public function test_it_leaves_out_anonymized_accounts(): void
    {
        $user = $this->anAccount('avatars/eva');
        $user->forceFill(['anonymized_at' => CarbonImmutable::now()])->save();

        $this->assertSame([], $this->directory()->avatarsOf([$user->id]));
    }

    public function test_it_ignores_malformed_ids(): void
    {
        $this->assertSame([], $this->directory()->avatarsOf(['no-uuid', '']));
    }

    private function anAccount(?string $avatar): UserModel
    {
        $user = UserModel::factory()->create();
        $user->forceFill(['avatar_public_id' => $avatar])->save();

        return $user;
    }

    private function directory(): AccountDirectoryInterface
    {
        return app(AccountDirectoryInterface::class);
    }
}
