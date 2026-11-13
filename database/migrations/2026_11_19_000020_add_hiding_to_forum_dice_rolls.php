<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hidden rolls: whoever threw a roll, whether its result is kept from the rest of the readers,
 * and who revealed it and when (once, for good). Rolls thrown before this keep no thrower and
 * stay public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_dice_rolls', static function (Blueprint $table): void {
            $table->boolean('is_hidden')->default(false);
            // An account id, kept when the account is anonymized (its name is read when painting)
            $table->uuid('rolled_by')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->uuid('revealed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forum_dice_rolls', static function (Blueprint $table): void {
            $table->dropColumn(['is_hidden', 'rolled_by', 'revealed_at', 'revealed_by']);
        });
    }
};
