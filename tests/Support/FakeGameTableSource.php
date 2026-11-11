<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

final class FakeGameTableSource extends FakePublicationSource
{
    public const string TYPE = 'game-table';

    public function type(): string
    {
        return self::TYPE;
    }
}
