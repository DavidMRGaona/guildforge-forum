<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * forum_member_titles: the short title whoever moderates the whole forum gives a person (forum#121),
 * one per person, and who gave it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_member_titles', static function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->string('title', 30);
            $table->uuid('moderator_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('moderator_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_member_titles');
    }
};
