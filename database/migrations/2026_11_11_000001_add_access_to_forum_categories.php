<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who reads, writes in and moderates each category, its look, the key of the categories the
 * forum relies on, and the game-system tags of discussions (for filtering, never for access).
 * A category whose rules are missing or unreadable is shown to nobody (CategoryAccessPolicy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->string('color', 7)->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('system_key', 32)->nullable()->unique();
            $table->json('view_rule')->nullable();
            $table->json('write_rule')->nullable();
            $table->json('moderators')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('forum_discussion_topics', static function (Blueprint $table): void {
            $table->uuid('discussion_id');
            $table->string('key', 64);
            $table->string('label', 120);

            $table->primary(['discussion_id', 'key']);
            $table->index('key');
        });

        Schema::table('forum_discussion_topics', static function (Blueprint $table): void {
            $table->foreign('discussion_id')->references('id')->on('forum_discussions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_discussion_topics');

        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropUnique(['system_key']);
            $table->dropColumn(['color', 'icon', 'system_key', 'view_rule', 'write_rule', 'moderators', 'subject_type', 'subject_id']);
        });
    }
};
