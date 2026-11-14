<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a category no longer unties the characters tied to it (forum#109): the foreign key
 * set their category to null, which made a player's character usable anywhere and left a
 * campaign's shared one without a space. The id stays; a character whose category is gone is
 * paused (CharacterRules::binding()) until its owner ties it elsewhere. The column keeps its
 * indexes for the lookups by category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_characters', static function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
        });
    }

    public function down(): void
    {
        // Characters of a category deleted meanwhile go back to being untied, as before
        $orphans = DB::table('forum_characters')
            ->whereNotNull('category_id')
            ->whereNotIn('category_id', DB::table('forum_categories')->select('id'));
        $orphans->update(['category_id' => null]);

        Schema::table('forum_characters', static function (Blueprint $table): void {
            $table->foreign('category_id')->references('id')->on('forum_categories')->nullOnDelete();
        });
    }
};
