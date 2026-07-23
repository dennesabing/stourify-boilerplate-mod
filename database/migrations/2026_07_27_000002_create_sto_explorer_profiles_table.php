<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Stourify-specific half of a user's identity: handle, bio, home city,
 * interests. The boilerplate's core `user_profiles` table (mobile number,
 * date of birth, default location) is untouched — this is domain data and
 * domain data lives in the module.
 *
 * `username` is the public @handle shown throughout the app and is unique
 * platform-wide, not per-organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_explorer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('home_city_id')->nullable()
                ->constrained('sto_cities')->nullOnDelete();

            $table->string('username', 30)->unique();
            $table->string('bio', 150)->nullable();
            $table->string('website')->nullable();

            // Onboarding "What are you into?" — chip selection, drives feed tuning.
            $table->json('interests')->nullable();

            // Denormalized graph counters; the profile header reads all three.
            $table->unsignedInteger('spots_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->unsignedInteger('following_count')->default(0);

            // Settings → Privacy. A private account turns follows into requests.
            $table->boolean('is_private')->default(false);
            $table->boolean('shows_location_on_spots')->default(true);

            $table->timestamps();

            $table->index(['organization_id', 'home_city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_explorer_profiles');
    }
};
