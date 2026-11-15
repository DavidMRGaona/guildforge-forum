<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a post spoke as a character that was deleted since: its `character_id` is emptied by
 * the foreign key, so without this the post could not be told from one written out of
 * character, and would name its writer by the account (forum#139). Posts of characters deleted
 * before this keep `false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->boolean('character_removed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('forum_posts', static function (Blueprint $table): void {
            $table->dropColumn('character_removed');
        });
    }
};
