<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Stourify\Enums\FollowStatus;

/**
 * The follow graph — a directed edge from follower to followee.
 *
 * `status` is Pending only when the followee runs a private account, in which
 * case the edge is a request until accepted. Both directions are indexed:
 * the Followers and Following lists read opposite sides of the same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_follows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followee_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 32)->default(FollowStatus::Active->value);
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            $table->unique(['follower_id', 'followee_id']);
            $table->index(['followee_id', 'status']);
            $table->index(['follower_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_follows');
    }
};
