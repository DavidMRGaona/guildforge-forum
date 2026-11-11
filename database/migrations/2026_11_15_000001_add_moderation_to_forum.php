<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderation.
 *
 * - forum_categories.approval_threshold: the first posts of a newcomer wait for approval in the
 *   category until they have this many published in the forum (0 = never).
 * - forum_discussions.awaiting_approval: its first post waits, so only its author and its
 *   moderators see it. title_by_moderation: moderation renamed it, so the forum never does.
 * - forum_posts.hidden_reason: why moderation hid it, for its author and moderators.
 * - forum_moderation_log: who did what, when and why. Rows are only ever added: discussions and
 *   posts it names have no foreign key, so deleting them keeps the record (with what it was
 *   called in details); accounts are kept as long as they exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('approval_threshold')->default(0);
        });

        Schema::table('forum_discussions', static function (Blueprint $table): void {
            $table->boolean('awaiting_approval')->default(false);
            $table->boolean('title_by_moderation')->default(false);
        });

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->string('hidden_reason', 300)->nullable();
        });

        Schema::create('forum_moderation_log', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 40);
            $table->uuid('moderator_id')->nullable();
            $table->uuid('subject_user_id')->nullable();
            $table->uuid('category_id')->nullable();
            $table->uuid('discussion_id')->nullable();
            $table->uuid('post_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->foreign('moderator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('subject_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('created_at');
            $table->index(['discussion_id', 'created_at']);
            $table->index(['moderator_id', 'created_at']);
            $table->index(['subject_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_moderation_log');

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropColumn('hidden_reason');
        });

        Schema::table('forum_discussions', static function (Blueprint $table): void {
            $table->dropColumn(['awaiting_approval', 'title_by_moderation']);
        });

        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->dropColumn('approval_threshold');
        });
    }
};
