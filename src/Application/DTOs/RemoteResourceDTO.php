<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * What a fetch brought: the bytes, and the address they came from after any redirect.
 */
final readonly class RemoteResourceDTO
{
    public function __construct(
        public string $bytes,
        public RemoteUrl $url,
    ) {}
}
