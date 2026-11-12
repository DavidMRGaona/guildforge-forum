<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The copies the forum keeps of images from other sites, one per address: the server fetches an
 * image once, checks it and stores it under forum/external/, and readers only ever get the copy.
 * Posts name both the address and the copy; this table is what says the copy is of that address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_external_images', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // sha256 of the address, so a long address can be looked up and kept unique
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->string('path', 120);
            $table->timestamp('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_external_images');
    }
};
