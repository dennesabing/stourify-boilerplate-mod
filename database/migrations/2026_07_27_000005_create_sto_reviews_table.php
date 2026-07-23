<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rating and write-up on a spot. One review per explorer per spot — editing
 * replaces, it does not stack.
 *
 * Review photos are media attachables; the "helpful" vote is a counter here
 * rather than a Reaction, because it is scoped to reviews alone and the
 * Reviews screen sorts on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->constrained('sto_spots')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');   // 1–5
            $table->text('body')->nullable();
            $table->unsignedInteger('helpful_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['spot_id', 'user_id']);
            $table->index(['spot_id', 'rating']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_reviews');
    }
};
