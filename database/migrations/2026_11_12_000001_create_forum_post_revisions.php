<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The versions of a post that its edits replaced, for moderators. A post's current body stays
 * in forum_posts; each edit first copies what it replaces here, under the discussion's lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_post_revisions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->unsignedInteger('number');
            $table->json('body');
            $table->text('plain_text');
            // Who made the edit that replaced this version
            $table->uuid('editor_id')->nullable();
            // When that edit happened
            $table->timestamp('created_at');

            $table->foreign('post_id')->references('id')->on('forum_posts')->cascadeOnDelete();
            $table->foreign('editor_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['post_id', 'number']);
            $table->index('editor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_post_revisions');
    }
};
