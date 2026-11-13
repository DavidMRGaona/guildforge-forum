<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use DateTimeImmutable;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Enums\PostStatus;

/**
 * A post's content as one reader sees it. `html` is null when the reader may not see what it
 * says (deleted by its author, hidden by a moderator); then `placeholder` says why.
 * `hiddenReason` is why moderation hid it, only for its author and its moderators. A moderator
 * may edit anyone's post (`canEdit`); only its author deletes it. `character` is who it speaks
 * as, when the reader may see that character (`moderatesCharacter`: the reader may change,
 * retire or delete it). `authorHidden`: it speaks as a shared character and the reader is neither
 * its author nor a moderator of its space, so the account that wrote it is not shown.
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
        public ?string $hiddenReason = null,
        public ?Character $character = null,
        public bool $moderatesCharacter = false,
        public bool $authorHidden = false,
    ) {}
}
