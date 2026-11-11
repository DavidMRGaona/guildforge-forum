<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an author deleted a post, and when its content (body and revisions) was purged. The
 * purge runs daily for posts deleted long enough ago (DeletedPostRetention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('content_purged_at')->nullable();

            $table->index(['status', 'content_purged_at', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropIndex(['status', 'content_purged_at', 'deleted_at']);
            $table->dropColumn(['deleted_at', 'content_purged_at']);
        });
    }
};
