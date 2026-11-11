<?php

declare(strict_types=1);

/*
 * Release defaults of the forum settings (read-only: what the panel saves goes to the settings table).
 */
return [
    // Discussions per page in a category (10–100)
    'discussions_per_page' => 20,
    // Posts per page in a discussion (10–100)
    'posts_per_page' => 20,
    // Posts one person may publish per minute (1–60)
    'posts_per_minute' => 6,
    // Discussions one person may start per hour (1–60)
    'discussions_per_hour' => 5,
    // Image formats people may upload (jpeg, png, webp, gif; never svg)
    'image_types' => ['jpeg', 'png', 'webp', 'gif'],
    // Largest image file, in kilobytes (128–10240)
    'image_max_kilobytes' => 4096,
    // Images are resized to this longest side, in pixels (320–4000)
    'image_max_dimension' => 2000,
    // Images one post may hold (1–20)
    'images_per_post' => 10,
    // Image uploads per person and hour (1–200)
    'image_uploads_per_hour' => 30,
    // Categories whose new discussions are announced on the site's channels (ids; only those guests can read)
    'announced_categories' => [],
];
