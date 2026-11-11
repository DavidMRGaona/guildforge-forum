<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\PostStatus;

/**
 * A post's content as one reader sees it. `html` is null when the reader may not see what it
 * says (deleted by its author, hidden by a moderator); then `placeholder` says why.
 */
final readonly class PostContentDTO
{
    public function __construct(
        public string $id,
        public ?int $number,
        public ?string $authorId,
        public PostStatus $status,
        public ?string $html,
        public ?string $placeholder,
        public ?DateTimeImmutable $editedAt,
        public bool $canEdit,
        public bool $canDelete,
    ) {}
}
