<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\Enums\CharacterAccessMode;
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
     * Discussions one person may start per hour.
     */
    public function discussionsPerHour(): int;

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

    /**
     * The categories whose new discussions are announced on the site's channels (only those
     * guests can read); none by default.
     *
     * @return list<string> category ids
     */
    public function announcedCategoryIds(): array;

    /** Who may have characters and write as them */
    public function characterAccess(): CharacterAccessMode;

    /**
     * The sites images may be taken from (each with its subdomains), as host names in
     * lowercase; none turns images from other sites off.
     *
     * @return list<string>
     */
    public function externalImageHosts(): array;
}
