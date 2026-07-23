<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Stourify\Enums\SpotStatus;

/**
 * A spot: the place itself, contributed by an explorer and shared by everyone.
 *
 * Geo strategy (see docs/mobile-delivery/technical-spec.md §5): no PostGIS.
 * Nearby queries bound a lat/lng box against the composite index below, then
 * order by Haversine distance in SQL. Portable across MySQL 8 and SQLite,
 * and sufficient at city scale.
 *
 * Photos, tags, comments and likes are NOT columns or child tables here —
 * they are core attachables (HasOrganizationMedia, HasTags, HasComments,
 * HasReactions). See saas-boilerplate/docs/system-wide-docs/system-attachables.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_spots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()
                ->constrained('sto_cities')->nullOnDelete();

            $table->string('title');
            $table->string('slug')->index();
            $table->text('description')->nullable();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address')->nullable();

            // Chip categories from the New Spot form: nature, foodie, coast, …
            $table->json('categories')->nullable();

            // Operating hours, keyed by weekday. Null = no fixed hours.
            $table->json('hours')->nullable();

            $table->string('status', 32)->default(SpotStatus::Draft->value);

            // Set when a business claims and verifies this spot (post-beta).
            $table->foreignId('owner_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->boolean('is_verified')->default(false);

            // Denormalized aggregates — every spot card renders these.
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Bounding-box prefilter for nearby queries.
            $table->index(['latitude', 'longitude']);
            $table->index(['organization_id', 'status']);
            $table->index(['city_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_spots');
    }
};
