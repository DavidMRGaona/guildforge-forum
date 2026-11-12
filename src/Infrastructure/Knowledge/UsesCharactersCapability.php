<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Services\CharacterRules;

/**
 * The person may create characters and write as them, as the forum's settings and permission say.
 */
final readonly class UsesCharactersCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private CharacterRules $rules) {}

    public function key(): string
    {
        return 'forum.uses_characters';
    }

    public function passes(object $user): bool
    {
        return $user instanceof Authenticatable && $this->rules->mayHaveCharacters($user);
    }
}
