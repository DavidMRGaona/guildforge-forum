<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private conversations between people of the community (forum#18). None of these tables is
 * read by the search, the moderation queues or the media library: only the people in a
 * conversation read it, and moderation reads a copy of what was reported.
 *
 * - forum_conversations: a title and where its numbering is. `last_number` counts every entry
 *   (messages and the notes of who joined or left); `last_message_number` only messages, so a
 *   note does not make a conversation unread. It goes when its last person leaves.
 * - forum_conversation_participants: who is in it now (leaving deletes the row) and how far they
 *   read; `mailed_through` is the last message a mail went out for, so one mail goes per
 *   conversation until the person reads it again.
 * - forum_conversation_messages: numbered from 1 within a conversation. A note of someone added
 *   or leaving has no body; `subject_user_id` is who was added. A message its author deleted or
 *   moderation hid keeps no text.
 * - forum_conversation_starts: when each person started a conversation, for the limit per day.
 *   Kept apart so leaving or a conversation that goes does not give a start back.
 * - forum_user_blocks: who does not want to hear from whom. One way only.
 * - forum_conversation_reports: a reported message, with a copy of it and of the messages around
 *   it as they were when reported: what moderation reads, and nothing else of the conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_conversations', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title', 120);
            $table->unsignedInteger('last_number')->default(0);
            $table->unsignedInteger('last_message_number')->default(0);
            $table->timestamp('last_activity_at');
            $table->timestamps();
        });

        Schema::create('forum_conversation_participants', static function (Blueprint $table): void {
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->unsignedInteger('read_through')->default(0);
            $table->unsignedInteger('mailed_through')->nullable();
            $table->timestamp('joined_at');

            $table->primary(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('forum_conversation_participants', static function (Blueprint $table): void {
            $table->foreign('conversation_id')->references('id')->on('forum_conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('forum_conversation_messages', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->unsignedInteger('number');
            $table->string('kind', 16);
            $table->uuid('author_id')->nullable();
            $table->uuid('subject_user_id')->nullable();
            $table->json('body')->nullable();
            $table->text('plain_text')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 500)->nullable();

            $table->unique(['conversation_id', 'number']);
        });

        Schema::table('forum_conversation_messages', static function (Blueprint $table): void {
            $table->foreign('conversation_id')->references('id')->on('forum_conversations')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('subject_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('forum_conversation_starts', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('forum_user_blocks', static function (Blueprint $table): void {
            $table->uuid('blocker_user_id');
            $table->uuid('blocked_user_id');
            $table->timestamp('created_at');

            $table->primary(['blocker_user_id', 'blocked_user_id']);
            $table->index('blocked_user_id');
        });

        Schema::table('forum_user_blocks', static function (Blueprint $table): void {
            $table->foreign('blocker_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('blocked_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('forum_conversation_reports', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // The message may go with its conversation: the copy below is what is kept
            $table->uuid('message_id')->nullable();
            $table->uuid('message_author_id')->nullable();
            $table->uuid('reporter_id')->nullable();
            $table->string('reason', 32);
            $table->string('note', 500)->nullable();
            $table->json('context');
            $table->string('resolution', 16)->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['resolution', 'created_at']);
            $table->index(['message_id', 'resolution']);
        });

        Schema::table('forum_conversation_reports', static function (Blueprint $table): void {
            $table->foreign('message_id')->references('id')->on('forum_conversation_messages')->nullOnDelete();
            $table->foreign('message_author_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reporter_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_conversation_reports');
        Schema::dropIfExists('forum_user_blocks');
        Schema::dropIfExists('forum_conversation_starts');
        Schema::dropIfExists('forum_conversation_messages');
        Schema::dropIfExists('forum_conversation_participants');
        Schema::dropIfExists('forum_conversations');
    }
};
