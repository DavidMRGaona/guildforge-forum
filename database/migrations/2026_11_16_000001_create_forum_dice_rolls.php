<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dice rolls of posts. The server throws them when a post is published and keeps the throw
 * here, apart from the post's document, which carries only the roll's id. A roll an edit takes
 * out of the post stays (with removed_at), so what was thrown is never lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_dice_rolls', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            // Its place among the post's rolls, from 1, in document order
            $table->unsignedSmallInteger('position');
            // As the server read it (lowercase, no spaces); what was written when it could not be read
            $table->string('expression', 200);
            // Dice thrown before explosions: what the post's budget counts
            $table->unsignedSmallInteger('dice_count')->default(0);
            // The terms with every die, and the total; both empty when the roll was not thrown
            $table->json('result')->nullable();
            $table->integer('total')->nullable();
            // Why it was not thrown (an invalid expression or a limit)
            $table->string('error', 40)->nullable();
            $table->boolean('added_on_edit')->default(false);
            $table->timestamp('rolled_at');
            $table->timestamp('removed_at')->nullable();

            $table->foreign('post_id')->references('id')->on('forum_posts')->cascadeOnDelete();
            $table->index(['post_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_dice_rolls');
    }
};
