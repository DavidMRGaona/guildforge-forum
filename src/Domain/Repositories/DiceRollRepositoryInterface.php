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

    public function deleteOfPost(PostId $postId): void;
}
