<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automatic discussion of each publication (a game table, a campaign, an event), keyed by
 * the publication's type and id so publishing again never opens another one, and what the forum
 * last said about it (facts), so a notice is never repeated however many times it is saved.
 *
 * - facts is null until the publication had a discussion; a row whose discussion is gone (a
 *   moderator deleted it) keeps its facts, so the discussion is never opened again.
 * - closed_by_forum says the forum closed it itself (cancelled, taken down): only then does it
 *   open it again. A discussion a moderator closed stays closed.
 * - is_live marks the publications the forum still follows (published, not over), for the daily
 *   reconciliation; next_check_at is the time a check was queued for (registrations closing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_subject_discussions', static function (Blueprint $table): void {
            $table->string('subject_type', 64);
            $table->string('subject_id', 100);
            $table->uuid('discussion_id')->nullable()->unique();
            $table->uuid('first_post_id')->nullable();
            $table->json('facts')->nullable();
            $table->boolean('closed_by_forum')->default(false);
            $table->boolean('is_live')->default(false);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();

            $table->primary(['subject_type', 'subject_id']);
            $table->index(['subject_type', 'is_live']);
        });

        Schema::table('forum_subject_discussions', static function (Blueprint $table): void {
            $table->foreign('discussion_id')->references('id')->on('forum_discussions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_subject_discussions');
    }
};
