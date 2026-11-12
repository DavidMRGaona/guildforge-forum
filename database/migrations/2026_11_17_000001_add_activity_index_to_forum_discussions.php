<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages ask every minute which discussions of a followed category moved lately. Walking a
 * category newest first and stopping at the first hundred unread ones needs its discussions
 * ordered by activity, which the existing (category, hidden, pinned, activity) index cannot give
 * (pinned sits in between): on PostgreSQL the question went from ~46 ms (a sort of every recent
 * discussion of the category) to ~6 ms with 10 000 of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_discussions', static function (Blueprint $table): void {
            $table->index(['category_id', 'last_activity_at'], 'forum_discussions_category_activity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('forum_discussions', static function (Blueprint $table): void {
            $table->dropIndex('forum_discussions_category_activity_idx');
        });
    }
};
