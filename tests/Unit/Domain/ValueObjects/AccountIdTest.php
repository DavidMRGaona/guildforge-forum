<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\ValueObjects\AccountId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountIdTest extends TestCase
{
    private const string ID = '0197a5c2-7d3e-7a41-9b52-3c1d8e6f4a70';

    public function test_it_lowercases_a_canonical_uuid(): void
    {
        $this->assertSame(self::ID, AccountId::normalize(self::ID));
        $this->assertSame(self::ID, AccountId::normalize(strtoupper(self::ID)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notCanonical(): array
    {
        return [
            'empty' => [''],
            'text' => ['no-es-un-id'],
            'urn' => ['urn:uuid:'.self::ID],
            'uuid prefix' => ['uuid:'.self::ID],
            'braces' => ['{'.self::ID.'}'],
            'trailing brace' => [self::ID.'}}'],
            'trailing newline' => [self::ID."\n"],
            'leading space' => [' '.self::ID],
            'without dashes' => [str_replace('-', '', self::ID)],
            'too long' => [self::ID.'0'],
        ];
    }

    #[DataProvider('notCanonical')]
    public function test_it_rejects_what_is_not_the_canonical_form(string $value): void
    {
        $this->assertNull(AccountId::normalize($value));
    }
}
