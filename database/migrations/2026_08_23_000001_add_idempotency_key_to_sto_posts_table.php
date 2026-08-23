<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a client deduplicate its own retried post.
 *
 * `POST /posts` mints the post's id server-side, so the app learns it only when
 * the answer arrives. Between the server committing and the app writing that id
 * down there is a window: kill the process inside it and the post exists while
 * nothing on the phone knows it does. The next attempt makes a second one
 * (STOURIFY-166).
 *
 * Nothing the client can do closes that window — it cannot know an id the server
 * has not told it yet. What closes it is the client NAMING the request, so the
 * server can recognise the second arrival as the same one. This column stores
 * that name.
 *
 * Scoped to the author rather than made globally unique. A key is only ever
 * meaningful to the client that minted it, and two people whose apps happen to
 * produce the same string must not be able to reach each other's posts. Per
 * author is the narrowest scope that still deduplicates, and the strongest one
 * that cannot leak — the same reasoning as the `media` table's equivalent
 * column, which scopes to the record the file hangs off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_posts', function (Blueprint $table): void {
            // Nullable: every post already in the table has no key, and a
            // caller that sends none keeps today's behaviour exactly. A unique
            // index permits any number of NULLs, so the constraint below is
            // simply inert for them.
            $table->string('idempotency_key', 64)->nullable()->after('uuid');

            $table->unique(['user_id', 'idempotency_key'], 'sto_posts_author_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sto_posts', function (Blueprint $table): void {
            $table->dropUnique('sto_posts_author_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
