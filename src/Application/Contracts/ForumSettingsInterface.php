<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\Enums\PostImageType;

interface ForumSettingsInterface
{
    /**
     * Discussions per page in a category list.
     */
    public function discussionsPerPage(): int;

    /**
     * Posts per page in a discussion.
     */
    public function postsPerPage(): int;

    /**
     * Posts one person may publish per minute (replies and first posts of new discussions).
     */
    public function postsPerMinute(): int;

    /**
     * Image formats people may upload; none when every one was turned off.
     *
     * @return list<PostImageType>
     */
    public function imageTypes(): array;

    /** Largest image file accepted, in kilobytes */
    public function imageMaxKilobytes(): int;

    /** Images are resized to this many pixels on their longest side */
    public function imageMaxDimension(): int;

    public function imagesPerPost(): int;

    /** Image uploads one person may make per hour */
    public function imageUploadsPerHour(): int;
}
