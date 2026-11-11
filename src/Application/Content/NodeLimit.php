<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

/**
 * How many nodes of a type one document may hold, and the reason given when it holds more.
 */
final readonly class NodeLimit
{
    public function __construct(
        public int $max,
        public string $reason,
    ) {}
}
