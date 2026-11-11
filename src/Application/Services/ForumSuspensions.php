<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Throwable;

/**
 * Whether someone is suspended in the forum right now: they read, but do not post, like or
 * report. Whatever lets people do those (the access rules, likes, reports) asks here.
 *
 * It fails closed: when the suspensions cannot be read, the person counts as suspended. Read
 * once per person and request (bound scoped).
 */
final class ForumSuspensions
{
    /** @var array<string, Suspension|null> */
    private array $active = [];

    public function __construct(
        private readonly SuspensionRepositoryInterface $suspensions,
    ) {}

    public function isSuspended(?Authenticatable $user): bool
    {
        $id = ForumBrowser::accountId($user);
        if ($id === null) {
            return false;
        }

        try {
            return $this->activeFor($id) instanceof Suspension;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /**
     * The suspension in force for the account, to tell them why and until when.
     */
    public function activeFor(string $accountId): ?Suspension
    {
        $accountId = strtolower($accountId);
        if (! array_key_exists($accountId, $this->active)) {
            $this->active[$accountId] = $this->suspensions->activeFor($accountId, CarbonImmutable::now());
        }

        return $this->active[$accountId];
    }

    /**
     * Forgets what was read, after suspending or lifting within the same request.
     */
    public function forget(string $accountId): void
    {
        unset($this->active[strtolower($accountId)]);
    }
}
