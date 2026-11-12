<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * Characters stored as the forum stores them.
 */
trait BuildsCharacters
{
    protected function aCharacterOf(string $ownerId, string $name = 'Arwen', ?Category $boundTo = null, bool $retired = false, bool $lockedByModeration = false): Character
    {
        $character = Character::create(CharacterId::generate(), $ownerId, $name, 'Elfa de Rivendel', $boundTo?->id(), new DateTimeImmutable('2026-11-12 09:00:00'));
        if ($retired || $lockedByModeration) {
            $character->retire(new DateTimeImmutable('2026-11-12 10:00:00'), byModeration: $lockedByModeration);
        }
        app(CharacterRepositoryInterface::class)->save($character);

        return $character;
    }
}
