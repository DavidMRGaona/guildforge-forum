<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where moderation sent posts and discussions, so no old address breaks and no number is given
 * out twice:
 * - forum_post_redirects: a post that left a discussion (split off, or merged into another)
 *   under the number it had there. It names the post itself, which keeps its id wherever it
 *   goes, so later moves need no rewriting. The discussion it left may be gone (merged), so
 *   that id has no foreign key; should the post be deleted with its new discussion, the row
 *   stays without it, keeping the number taken and the gap explained.
 * - forum_discussion_redirects: a discussion merged into another, under its public number,
 *   which no other discussion takes. Merging the one it joined points it further on.
 * - forum_posts (discussion_id, published_at): a discussion's last activity is its latest
 *   publication, no longer its last number (merged posts are older than the ones before them),
 *   and the counters look it up with every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_post_redirects', static function (Blueprint $table): void {
            $table->uuid('from_discussion_id');
            $table->unsignedInteger('from_number');
            $table->uuid('post_id')->nullable();
            $table->timestamp('created_at');

            $table->primary(['from_discussion_id', 'from_number']);
            $table->index('post_id');
        });

        Schema::table('forum_post_redirects', static function (Blueprint $table): void {
            $table->foreign('post_id')->references('id')->on('forum_posts')->nullOnDelete();
        });

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->index(['discussion_id', 'published_at']);
        });

        Schema::create('forum_discussion_redirects', static function (Blueprint $table): void {
            $table->unsignedInteger('from_number')->primary();
            $table->uuid('from_discussion_id')->unique();
            $table->uuid('to_discussion_id')->nullable();
            $table->timestamp('created_at');

            $table->index('to_discussion_id');
        });

        Schema::table('forum_discussion_redirects', static function (Blueprint $table): void {
            $table->foreign('to_discussion_id')->references('id')->on('forum_discussions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_discussion_redirects');
        Schema::dropIfExists('forum_post_redirects');

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropIndex(['discussion_id', 'published_at']);
        });
    }
};
