<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only log of deletes for the offline-sync delta contract.
 *
 * Deliberately not a domain entity: no `uuid` (nothing ever references a
 * tombstone by its own identity), no `HasUuid` / `BelongsToOrganization`
 * global-scope machinery (see SyncTombstone) — every query against this table
 * filters explicitly instead, the same way `sto_sync_tombstones` itself is
 * filtered by `SyncController`'s delta path.
 *
 * One row per delete, uniform across a hard delete (follows, wishlist items)
 * and a soft delete (spots, reviews, cities). A follow writes two rows — one
 * per party — so the edge's removal reaches both sides' deltas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_sync_tombstones', function (Blueprint $table): void {
            $table->id();

            // The morph alias (e.g. `stourify_spot`), not the FQCN — see
            // StourifyServiceProvider's morph map.
            $table->string('entity_type', 64);
            $table->uuid('entity_uuid');

            // Null = a global/reference-data delete (cities), visible to every caller's delta.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->timestamp('deleted_at');
            $table->timestamps();

            $table->index(['user_id', 'deleted_at']);
            $table->index(['entity_type', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_sync_tombstones');
    }
};
