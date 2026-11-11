<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\Enums\PublicationState;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What the forum last said about a publication: the facts its notices are about. A notice is
 * told when a fact changes from what was stored, and the new value is stored in the same
 * transaction, so saving the publication twice (or two checks of it) never tells it twice.
 */
final readonly class PublicationFacts
{
    public const string PUBLISHED = 'published';

    public const string WITHDRAWN = 'withdrawn';

    public const string DELETED = 'deleted';

    /**
     * @param  string  $presence  published, withdrawn (taken down) or deleted
     * @param  string|null  $startsAt  ISO 8601 in UTC
     * @param  string  $opening  fingerprint of the first post the forum wrote for it
     */
    public function __construct(
        public string $presence,
        public PublicationState $state,
        public ?string $startsAt,
        public bool $isFull,
        public bool $registrationOpen,
        public string $title,
        public string $opening,
    ) {}

    public static function of(PublicationDTO $publication, string $opening): self
    {
        return new self(
            self::PUBLISHED,
            $publication->state,
            self::instant($publication->startsAt),
            $publication->isFull,
            $publication->registrationOpen,
            $publication->title,
            $opening,
        );
    }

    /**
     * The same facts once the publication is no longer published: whatever it was when last
     * seen is kept, so publishing it again tells only what changed meanwhile.
     */
    public function gone(bool $deleted): self
    {
        return new self($deleted ? self::DELETED : self::WITHDRAWN, $this->state, $this->startsAt, $this->isFull, $this->registrationOpen, $this->title, $this->opening);
    }

    public function isPublished(): bool
    {
        return $this->presence === self::PUBLISHED;
    }

    /**
     * Published and not over: the forum still follows it.
     */
    public function isLive(): bool
    {
        return $this->isPublished() && $this->state !== PublicationState::Ended;
    }

    /**
     * Its discussion should be closed: cancelled, taken down or deleted.
     */
    public function wantsClosed(): bool
    {
        return ! $this->isPublished() || $this->state === PublicationState::Cancelled;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'presence' => $this->presence,
            'state' => $this->state->value,
            'starts_at' => $this->startsAt,
            'is_full' => $this->isFull,
            'registration_open' => $this->registrationOpen,
            'title' => $this->title,
            'opening' => $this->opening,
        ];
    }

    /**
     * The stored facts, or null when there are none or they cannot be read (then the next read
     * only takes note, as the first one does).
     *
     * @param  array<string, scalar|null>|null  $facts
     */
    public static function fromArray(?array $facts): ?self
    {
        if ($facts === null) {
            return null;
        }

        $presence = $facts['presence'] ?? null;
        $state = is_string($facts['state'] ?? null) ? PublicationState::tryFrom($facts['state']) : null;
        $startsAt = $facts['starts_at'] ?? null;
        $isFull = $facts['is_full'] ?? null;
        $open = $facts['registration_open'] ?? null;
        $title = $facts['title'] ?? null;
        $opening = $facts['opening'] ?? null;

        if (! in_array($presence, [self::PUBLISHED, self::WITHDRAWN, self::DELETED], true)
            || ! $state instanceof PublicationState
            || ! is_string($startsAt) && $startsAt !== null
            || ! is_bool($isFull) || ! is_bool($open)
            || ! is_string($title) || ! is_string($opening)) {
            return null;
        }

        return new self($presence, $state, $startsAt, $isFull, $open, $title, $opening);
    }

    private static function instant(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
