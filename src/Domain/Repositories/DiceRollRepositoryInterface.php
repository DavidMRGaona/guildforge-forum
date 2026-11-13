<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\ValueObjects\PostId;

interface DiceRollRepositoryInterface
{
    /**
     * Stores a roll; one already stored only has its position and removal updated, never its
     * result.
     */
    public function save(DiceRoll $roll): void;

    /**
     * Every roll of the post, removed ones too, in the order they were thrown.
     *
     * @return list<DiceRoll>
     */
    public function ofPost(PostId $postId): array;

    /**
     * The rolls with these ids, wherever they belong.
     *
     * @param  list<string>  $ids
     * @return list<DiceRoll>
     */
    public function findMany(array $ids): array;

    /**
     * Reveals a hidden roll that is still secret and in its post, recording who and when; false
     * when there was nothing to reveal (someone else just did, or it was never hidden).
     */
    public function markRevealed(string $id, string $accountId, \DateTimeImmutable $at): bool;

    public function deleteOfPost(PostId $postId): void;
}
