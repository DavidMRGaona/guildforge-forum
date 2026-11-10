<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use PHPUnit\Framework\TestCase;

final class CategorySubjectTest extends TestCase
{
    public function test_it_names_a_publication(): void
    {
        $subject = new CategorySubject('campaign', 'a1b2c3d4-0000-4000-8000-000000000001');

        $this->assertSame('campaign', $subject->type);
        $this->assertSame('a1b2c3d4-0000-4000-8000-000000000001', $subject->id);
        $this->assertTrue($subject->equals(new CategorySubject('campaign', 'a1b2c3d4-0000-4000-8000-000000000001')));
    }

    public function test_the_type_is_a_publication_type(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategorySubject('Campaña', 'x');
    }

    public function test_the_id_is_not_blank(): void
    {
        $this->expectException(InvalidCategoryException::class);

        new CategorySubject('campaign', ' ');
    }
}
