<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;

/**
 * A tag a discussion carries for filtering, taken from its publication's topics (its game
 * system: "game-system.<uuid>"). Tags never decide who reads or writes anything.
 */
final readonly class DiscussionTopic
{
    public const string GAME_SYSTEM_PREFIX = 'game-system.';

    public const int MAX_LABEL_LENGTH = 120;

    public string $label;

    /**
     * @throws InvalidDiscussionException
     */
    public function __construct(
        public string $key,
        string $label,
    ) {
        if (preg_match('/^[a-z0-9._-]{1,64}\z/', $key) !== 1) {
            throw InvalidDiscussionException::invalidTopic($key);
        }

        $label = trim($label);
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw InvalidDiscussionException::invalidTopic($key);
        }

        $this->label = $label;
    }

    public function isGameSystem(): bool
    {
        return str_starts_with($this->key, self::GAME_SYSTEM_PREFIX) && strlen($this->key) > strlen(self::GAME_SYSTEM_PREFIX);
    }
}
