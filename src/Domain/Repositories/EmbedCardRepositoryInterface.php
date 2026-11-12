<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\EmbedCard;

interface EmbedCardRepositoryInterface
{
    public function find(string $service, string $embedId): ?EmbedCard;

    /**
     * The cards of several embedded posts.
     *
     * @param  list<array{string, string}>  $embeds  service and id of each
     * @return list<EmbedCard>
     */
    public function findMany(array $embeds): array;

    /** Stores a card, replacing the one of the same post */
    public function save(EmbedCard $card): void;

    /**
     * The cards whose thumbnail is a stored file.
     *
     * @return list<EmbedCard>
     */
    public function withThumbnails(): array;
}
