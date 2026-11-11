<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only categories: nobody writes in them but the forum's moderators. read_only_by says who
 * set it, the forum itself (a campaign's space once the campaign is over, cancelled or taken
 * down) or moderation; the forum only ever lifts what it set. Null means it takes posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->string('read_only_by', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->dropColumn('read_only_by');
        });
    }
};
