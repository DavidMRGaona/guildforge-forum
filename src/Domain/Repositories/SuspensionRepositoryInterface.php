<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\ValueObjects\SuspensionId;

interface SuspensionRepositoryInterface
{
    public function save(Suspension $suspension): void;

    public function find(SuspensionId $id): ?Suspension;

    /**
     * The person's suspension in force at that moment (the one that ends last, if several).
     */
    public function activeFor(string $userId, DateTimeImmutable $at): ?Suspension;
}
