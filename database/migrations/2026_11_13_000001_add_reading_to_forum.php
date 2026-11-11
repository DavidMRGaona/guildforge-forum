<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the public pages need:
 * - Each discussion's public number (1, 2, 3…, the "42" of /foro/t/42-its-title), given out when
 *   it starts; two discussions started at once retry until each has its own.
 * - How far each person read each discussion (the highest post number they have seen). It only
 *   moves forward, and only when the page reports the posts on screen, never because a page was sent.
 * - When each person marked a category as read: whatever happened in it before then counts as
 *   read, so marking the whole forum writes one row per category, not one per discussion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_discussions', static function (Blueprint $table): void {
            $table->unsignedInteger('number')->nullable()->unique();
        });

        Schema::create('forum_discussion_reads', static function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('discussion_id');
            $table->unsignedInteger('last_read_number');
            $table->timestamp('read_at');

            $table->primary(['user_id', 'discussion_id']);
            $table->index('discussion_id');
        });

        Schema::table('forum_discussion_reads', static function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('discussion_id')->references('id')->on('forum_discussions')->cascadeOnDelete();
        });

        Schema::create('forum_category_reads', static function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('category_id');
            $table->timestamp('marked_read_at');

            $table->primary(['user_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::table('forum_category_reads', static function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('forum_categories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_category_reads');
        Schema::dropIfExists('forum_discussion_reads');

        Schema::table('forum_discussions', static function (Blueprint $table): void {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
