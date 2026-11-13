<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Characters shared by the directors of a campaign: they belong to the campaign's space (their
 * category), not to an account, so they have no owner. The posts that speak as them keep the
 * author's account as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_characters', static function (Blueprint $table): void {
            $table->uuid('owner_id')->nullable()->change();
            // The shared characters of a space, by name
            $table->index(['category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('forum_characters', static function (Blueprint $table): void {
            $table->dropIndex(['category_id', 'name']);
        });

        // Shared characters have nobody to belong to: their posts show the account instead
        DB::table('forum_characters')->whereNull('owner_id')->delete();

        Schema::table('forum_characters', static function (Blueprint $table): void {
            $table->uuid('owner_id')->nullable(false)->change();
        });
    }
};
