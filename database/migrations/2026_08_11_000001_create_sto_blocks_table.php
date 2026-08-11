<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocks — a directed row whose effect is symmetric.
 *
 * The row records who did the blocking, because only they may lift it. Every
 * *read* built on it asks the undirected question — "is either of these two
 * blocked by the other" — which is why both columns are indexed on their own
 * rather than only as the leading pair.
 *
 * No `status` and no soft deletes: a block is on or gone. A tombstone would
 * also collide with the unique index the moment someone re-blocked the same
 * person, the same reasoning `sto_follows` documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_blocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id']);
            $table->index('blocked_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_blocks');
    }
};
