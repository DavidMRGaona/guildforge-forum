<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likes, follows and the forum's own preference of each person:
 * - One like per person and post; it goes away with the post or the account.
 * - How each person follows a discussion: «following» (notified of every reply) or «normal», kept
 *   when they chose it so that replying does not follow it again. Muting is the core's mute of
 *   the discussion (the same one mail footers use), not a row here. `mailed_through` is the last
 *   post a reply mail went out for: no other is mailed until the person reads past it.
 * - The categories each person follows (notified of new discussions), and whether the follow was
 *   theirs or came with a campaign's roster, which takes it back when they leave.
 * - Whether starting or replying to a discussion follows it (on unless the person turned it off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_post_likes', static function (Blueprint $table): void {
            $table->uuid('post_id');
            $table->uuid('user_id');
            $table->timestamp('created_at');

            $table->primary(['post_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('forum_post_likes', static function (Blueprint $table): void {
            $table->foreign('post_id')->references('id')->on('forum_posts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('forum_discussion_follows', static function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('discussion_id');
            $table->string('level', 16);
            $table->unsignedInteger('mailed_through')->nullable();
            $table->timestamp('updated_at');

            $table->primary(['user_id', 'discussion_id']);
            $table->index(['discussion_id', 'level']);
        });

        Schema::table('forum_discussion_follows', static function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('discussion_id')->references('id')->on('forum_discussions')->cascadeOnDelete();
        });

        Schema::create('forum_category_follows', static function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('category_id');
            $table->string('origin', 16);
            $table->timestamp('created_at');

            $table->primary(['user_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::table('forum_category_follows', static function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('forum_categories')->cascadeOnDelete();
        });

        Schema::create('forum_user_preferences', static function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->boolean('auto_follow')->default(true);
            $table->timestamp('updated_at');
        });

        Schema::table('forum_user_preferences', static function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_user_preferences');
        Schema::dropIfExists('forum_category_follows');
        Schema::dropIfExists('forum_discussion_follows');
        Schema::dropIfExists('forum_post_likes');
    }
};
