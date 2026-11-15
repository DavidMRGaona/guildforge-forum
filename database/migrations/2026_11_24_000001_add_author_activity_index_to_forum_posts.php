<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A public profile lists a person's latest visible messages and counts them on every visit
 * (forum#135). The index on the author alone would read every message they ever wrote and sort
 * them; with the status and the publication time after it, the newest visible ones are read in
 * order and the count stays on the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->index(['author_id', 'status', 'published_at'], 'forum_posts_author_activity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropIndex('forum_posts_author_activity_idx');
        });
    }
};
