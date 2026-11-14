<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * forum_post_references: which posts each post points at, by «#N» or by quoting them
 * (forum#122), so a post's replies are counted and listed without reading every post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_post_references', static function (Blueprint $table): void {
            $table->uuid('source_post_id');
            $table->uuid('target_post_id');
            $table->string('kind', 16);

            $table->foreign('source_post_id')->references('id')->on('forum_posts')->cascadeOnDelete();
            $table->foreign('target_post_id')->references('id')->on('forum_posts')->cascadeOnDelete();
            $table->unique(['source_post_id', 'target_post_id', 'kind']);
            $table->index('target_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_post_references');
    }
};
