<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cards of embedded posts, one per service and post: what the server took once from the
 * service's oEmbed data (plain texts, never its HTML), the forum's copy of the thumbnail, and
 * whether the service still gave the post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_embeds', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('service', 20);
            $table->string('embed_id', 200);
            $table->boolean('available');
            $table->string('title', 300)->nullable();
            $table->string('author_name', 120)->nullable();
            // A copy under forum/external/, like images from other sites
            $table->string('thumbnail_path', 120)->nullable();
            // What a player needs beyond the id (Bluesky's DID)
            $table->json('details')->nullable();
            $table->timestamp('fetched_at');

            $table->unique(['service', 'embed_id']);
            $table->index('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_embeds');
    }
};
