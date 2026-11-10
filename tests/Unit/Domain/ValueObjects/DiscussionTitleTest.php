<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use PHPUnit\Framework\TestCase;

final class DiscussionTitleTest extends TestCase
{
    public function test_the_title_is_trimmed(): void
    {
        $this->assertSame('Sesión del viernes', (new DiscussionTitle("  Sesión del viernes \n"))->value);
    }

    public function test_a_blank_title_is_refused(): void
    {
        $this->expectException(InvalidDiscussionException::class);

        new DiscussionTitle("  \t ");
    }

    public function test_the_title_counts_characters_not_bytes(): void
    {
        $title = str_repeat('ñ', DiscussionTitle::MAX_LENGTH);

        $this->assertSame($title, (new DiscussionTitle($title))->value);
    }

    public function test_a_longer_title_is_refused(): void
    {
        $this->expectException(InvalidDiscussionException::class);

        new DiscussionTitle(str_repeat('a', DiscussionTitle::MAX_LENGTH + 1));
    }
}
