<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One contributor's note about a spot — the corkboard beside the brass plaque.
 *
 * A spot's own `description` stays what it is: one text, one author, editable
 * only by them. This table is the other thing, and there are many of them per
 * spot. Each row carries who wrote it and when, and every row is likeable and
 * commentable through the platform's polymorphic attachment tables — which is
 * why neither a comment nor a reaction appears in this schema.
 *
 * `likes_count` is denormalized. The list is ordered by it on every request,
 * and counting reactions per row on read would be an aggregate that gets
 * slower exactly as a spot gets popular. `ReactionCountObserver` is its only
 * writer, recomputing it from the reactions table rather than incrementing —
 * an increment drifts the first time anything writes outside the happy path,
 * a scoped COUNT cannot.
 *
 * See specs/2026-08-22-spot-about-design.md (STOURIFY-145).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_spot_abouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->constrained('sto_spots')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('body');
            $table->unsignedInteger('likes_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The shape of every read this feature makes: one spot's entries,
            // best first.
            $table->index(['spot_id', 'likes_count']);

            // One explorer's own contributions, for a profile view and for the
            // account-deletion sweep that withdraws them.
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_spot_abouts');
    }
};
