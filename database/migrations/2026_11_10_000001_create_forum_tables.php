<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories, discussions and posts. Post numbers and publication times are given out in the
 * same order, under a lock on the discussion. The counters on categories and discussions (posts,
 * last post, last activity) are refreshed in the same transaction as every write
 * (ForumCountersInterface); last_post_id has no foreign key, since it is rewritten each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_categories', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 80);
            $table->string('slug', 64)->unique();
            $table->string('description', 300)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('discussion_count')->default(0);
            $table->unsignedInteger('post_count')->default(0);
            $table->uuid('last_post_id')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });

        // After the table exists: PostgreSQL needs the primary key before a key that points at it
        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('forum_categories')->restrictOnDelete();
        });

        Schema::create('forum_discussions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->uuid('author_id')->nullable();
            $table->string('title', 160);
            $table->string('status', 16)->default('open');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('post_count')->default(0);
            $table->uuid('last_post_id')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            // A category with discussions is never deleted: they are moved first
            $table->foreign('category_id')->references('id')->on('forum_categories')->restrictOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['category_id', 'is_hidden', 'is_pinned', 'last_activity_at']);
            $table->index('author_id');
        });

        Schema::create('forum_posts', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('discussion_id');
            // Null only while it awaits approval
            $table->unsignedInteger('number')->nullable();
            $table->uuid('author_id')->nullable();
            $table->string('status', 16);
            $table->json('body');
            $table->text('plain_text');
            // When it became readable (written, or approved); null while it awaits approval
            $table->timestamp('published_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->foreign('discussion_id')->references('id')->on('forum_discussions')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['discussion_id', 'number']);
            $table->index(['discussion_id', 'status', 'number']);
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_discussions');
        Schema::dropIfExists('forum_categories');
    }
};
