<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Characters people write as, and which one each post (and each earlier version of it) speaks
 * as. Deleting a character leaves its posts out of character; deleting an account takes its
 * characters with it. A character tied to a category that is deleted is tied to none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_characters', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('name', 60);
            $table->string('description', 300)->nullable();
            // forum/characters/<year>/<month>/<uuid>.<ext>, the folder the portrait media source claims
            $table->string('portrait_path', 255)->nullable();
            $table->uuid('category_id')->nullable();
            $table->timestamp('retired_at')->nullable();
            // Retired by moderation: only moderation brings it back
            $table->boolean('retirement_locked')->default(false);
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('forum_categories')->nullOnDelete();
            $table->index(['owner_id', 'name']);
        });

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->uuid('character_id')->nullable();
            $table->foreign('character_id')->references('id')->on('forum_characters')->nullOnDelete();
            // The last character someone used in a discussion
            $table->index(['discussion_id', 'author_id', 'character_id']);
        });

        Schema::table('forum_post_revisions', static function (Blueprint $table): void {
            $table->uuid('character_id')->nullable();
            $table->foreign('character_id')->references('id')->on('forum_characters')->nullOnDelete();
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::table('forum_post_revisions', static function (Blueprint $table): void {
            $table->dropForeign(['character_id']);
            $table->dropIndex(['character_id']);
            $table->dropColumn('character_id');
        });

        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropForeign(['character_id']);
            $table->dropIndex(['discussion_id', 'author_id', 'character_id']);
            $table->dropColumn('character_id');
        });

        Schema::dropIfExists('forum_characters');
    }
};
