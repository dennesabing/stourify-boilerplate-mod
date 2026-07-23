<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cities are curated reference data, not user-generated content: they anchor
 * the home-city choice in Onboarding, group the Wishlist, and back the
 * Featured Cities surface. `spot_count` is a denormalized counter kept fresh
 * by the spot lifecycle — Discover reads it on every render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_cities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('region')->nullable();
            $table->string('country', 2)->default('PH');

            // Centroid — used to bound "spots near this city" queries.
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->unsignedInteger('spot_count')->default(0);
            $table->boolean('is_featured')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_featured']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_cities');
    }
};
