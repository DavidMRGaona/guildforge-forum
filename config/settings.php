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
];
