<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only categories: nobody writes in them but the forum's moderators. read_only_by says who
 * set it, the forum itself (a campaign's space once the campaign is over, cancelled or taken
 * down) or moderation; the forum only ever lifts what it set. Null means it takes posts.
 *
 * space_open, on the publication's record, is whether the forum last saw the campaign of a space
 * active: the forum makes the space read-only (or lifts its own mark) only when that changes, so
 * what moderation decided in between stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->string('read_only_by', 16)->nullable();
        });

        Schema::table('forum_subject_discussions', static function (Blueprint $table): void {
            $table->boolean('space_open')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forum_subject_discussions', static function (Blueprint $table): void {
            $table->dropColumn('space_open');
        });

        Schema::table('forum_categories', static function (Blueprint $table): void {
            $table->dropColumn('read_only_by');
        });
    }
};
