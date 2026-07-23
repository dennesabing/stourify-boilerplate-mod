<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved spots. The Wishlist screen groups by city and offers a per-group
 * offline download, so `city_id` is denormalized off the spot to keep that
 * grouping query single-table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->constrained('sto_spots')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()
                ->constrained('sto_cities')->nullOnDelete();

            $table->text('note')->nullable();
            $table->boolean('is_downloaded_offline')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'spot_id']);
            $table->index(['user_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_wishlist_items');
    }
};
