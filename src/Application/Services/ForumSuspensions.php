<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Throwable;

/**
 * Whether someone is suspended in the forum right now: they read, but do not post, like or
 * report. Whatever lets people do those (the access rules, likes, reports) asks here.
 *
 * Whether someone is barred is wider: suspended, or an account that is deactivated or
 * anonymized (core#461). A deactivated account keeps everything it has in the forum, but does
 * nothing until it is restored, so nothing is stored about it: the account is asked every time.
 * Whatever lets people act (write, like, report, moderate, use characters) asks isBarred().
 *
 * Both fail closed: when the suspensions or the account cannot be read, the person counts as
 * suspended or barred. Read once per person and request (bound scoped).
 */
final class ForumSuspensions
{
    /** @var array<string, Suspension|null> */
    private array $active = [];

    /** @var array<string, bool> */
    private array $standing = [];

    public function __construct(
        private readonly SuspensionRepositoryInterface $suspensions,
        private readonly AccountDirectoryInterface $accounts,
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
     * Whether the person may not act in the forum: suspended, deactivated or anonymized.
     */
    public function isBarred(?Authenticatable $user): bool
    {
        $id = ForumBrowser::accountId($user);
        if ($id === null) {
            return false;
        }

        try {
            $this->standing[$id] ??= $this->accounts->active($id) instanceof Authenticatable;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }

        return ! $this->standing[$id] || $this->isSuspended($user);
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
        unset($this->active[strtolower($accountId)], $this->standing[strtolower($accountId)]);
    }
}
