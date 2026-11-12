<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\ValueObjects\PostBody;

/**
 * A post body once its rolls are settled: the document with every roll's id and its plain text
 * with the results, and the roll records to store once the post is saved.
 */
final readonly class RolledBody
{
    /**
     * @param  list<DiceRoll>  $records  the rolls to store: new ones, and kept or removed ones whose place changed
     */
    public function __construct(
        public PostBody $body,
        public array $records,
    ) {}
}
