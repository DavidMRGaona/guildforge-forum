<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A public profile lists a person's latest visible messages and counts them on every visit
 * (forum#135). With the index on the author alone, the database read every message the person
 * ever wrote (hidden, deleted and pending ones too) and sorted them all to find the newest. With
 * the status and the publication time after the author, it reads only their visible messages,
 * newest first, and the list stops as soon as it has its five in topics the reader may see. The
 * counters still visit every visible message of the person once, to check its topic: the cost
 * grows with what they wrote, not with the whole forum.
 *
 * The new index starts with the author, so it serves every lookup the old one did (the posts of
 * an account, and clearing the author when an account is deleted): the old one goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->index(['author_id', 'status', 'published_at'], 'forum_posts_author_activity_idx');
        });

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropIndex(['author_id']);
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->index('author_id');
        });

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropIndex('forum_posts_author_activity_idx');
        });
    }
};
