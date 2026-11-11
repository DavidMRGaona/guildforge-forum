<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports and suspensions.
 *
 * - forum_reports: someone telling moderation about a post. Open while resolution is null. A
 *   post that goes away (rejected, or its discussion deleted) takes its reports with it; what
 *   moderation did stays in the moderation log.
 * - forum_suspensions: someone kept from writing in the forum until ends_at (null: no end) or
 *   until lifted_at. It ends by itself: nothing has to run when its time comes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_reports', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->uuid('reporter_id')->nullable();
            $table->string('reason', 32);
            $table->string('note', 500)->nullable();
            $table->string('resolution', 16)->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at');

            $table->foreign('post_id')->references('id')->on('forum_posts')->cascadeOnDelete();
            $table->foreign('reporter_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['resolution', 'created_at']);
            $table->index(['post_id', 'resolution']);
        });

        Schema::create('forum_suspensions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('suspended_by')->nullable();
            $table->string('reason', 500);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->uuid('lifted_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('suspended_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('lifted_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'lifted_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_suspensions');
        Schema::dropIfExists('forum_reports');
    }
};
