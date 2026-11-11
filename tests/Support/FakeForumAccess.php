<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Discussion;

/**
 * Access rules decided by the test: who reads, writes and moderates, in every discussion.
 * Writing implies reading and moderating implies both, as the category rules do.
 */
final class FakeForumAccess implements ForumAccessInterface
{
    public bool $guestsRead = false;

    /** @var array<string, true> */
    private array $readers = [];

    /** @var array<string, true> */
    private array $writers = [];

    /** @var array<string, true> */
    private array $moderators = [];

    public function letRead(Authenticatable ...$users): self
    {
        foreach ($users as $user) {
            $this->readers[$this->id($user)] = true;
        }

        return $this;
    }

    public function letWrite(Authenticatable ...$users): self
    {
        foreach ($users as $user) {
            $this->writers[$this->id($user)] = true;
        }

        return $this->letRead(...$users);
    }

    public function letModerate(Authenticatable ...$users): self
    {
        foreach ($users as $user) {
            $this->moderators[$this->id($user)] = true;
        }

        return $this->letWrite(...$users);
    }

    public function canRead(?Authenticatable $user, Discussion $discussion): bool
    {
        return $user instanceof Authenticatable ? isset($this->readers[$this->id($user)]) : $this->guestsRead;
    }

    public function canWrite(?Authenticatable $user, Discussion $discussion): bool
    {
        return $user instanceof Authenticatable && isset($this->writers[$this->id($user)]);
    }

    public function canModerate(?Authenticatable $user, Discussion $discussion): bool
    {
        return $user instanceof Authenticatable && isset($this->moderators[$this->id($user)]);
    }

    private function id(Authenticatable $user): string
    {
        $id = $user->getAuthIdentifier();

        return strtolower(is_scalar($id) ? (string) $id : '');
    }
}
