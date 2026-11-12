<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Knowledge;

use App\Application\Knowledge\Contracts\KnowledgeCapabilityInterface;
use Modules\Forum\Application\Publications\PublicationAvailability;

/**
 * A source of publications of one type is offered (its module is enabled): the one rule the forum
 * uses to show or hide what it builds on it (PublicationAvailability), which fails closed.
 */
abstract readonly class OffersPublicationCapability implements KnowledgeCapabilityInterface
{
    public function __construct(private PublicationAvailability $availability) {}

    abstract protected function publicationType(): string;

    public function passes(object $user): bool
    {
        return $this->availability->offers($this->publicationType());
    }
}
