<?php

declare(strict_types=1);

/*
 * Release defaults of the forum settings (read-only: what the panel saves goes to the settings table).
 */
return [
    // Who sees the forum on the site: preview (only forum:forum.preview; admins hold every permission)
    // or open. A preview by default, so a site sees the forum only once someone opens it; the
    // module's test suite starts open (FORUM_ACCESS_DEFAULT in its phpunit.xml)
    'access' => env('FORUM_ACCESS_DEFAULT', 'preview'),
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
    // Sites images may be taken from, each with its subdomains (host names; https only). Empty turns it off
    'external_image_hosts' => ['imgur.com'],
    // Services whose posts may be embedded (reddit, x, bluesky, instagram, tiktok, youtube, vimeo, twitch)
    'embed_services' => ['reddit', 'x', 'bluesky', 'instagram', 'tiktok', 'youtube', 'vimeo', 'twitch'],
    // The cookie category whose consent loads each service's player without a click (cookie consent module)
    'embed_cookie_categories' => [
        'reddit' => 'marketing',
        'x' => 'marketing',
        'bluesky' => 'marketing',
        'instagram' => 'marketing',
        'tiktok' => 'marketing',
        'youtube' => 'marketing',
        'vimeo' => 'marketing',
        'twitch' => 'marketing',
    ],
    // Who may have characters and write as them: nobody, everyone (signed in) or permission (forum:characters.use)
    'characters_access' => 'everyone',
    // How each record page shows its conversation, by publication type (event, game-table, campaign…):
    // hidden, read (last posts, writing in the forum) or reply. A type not listed shows none
    'subject_conversations' => [],
    // Categories whose new discussions are announced on the site's channels (ids; only those guests can read)
    'announced_categories' => [],
];
