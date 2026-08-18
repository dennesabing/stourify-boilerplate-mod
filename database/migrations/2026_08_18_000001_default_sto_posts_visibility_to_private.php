<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Stourify\Enums\PostVisibility;

/**
 * A new post starts locked, not shared (STOURIFY-105).
 *
 * `sto_posts.visibility` used to default to `public`, so a row inserted
 * without naming a visibility was born visible to everyone. This flips that
 * default to `private` — the safe direction for a privacy setting, where
 * forgetting to choose costs the author nothing.
 *
 * IMPORTANT: this changes the column's DEFAULT and nothing else. It reads no
 * rows and writes no rows, so every post that already exists keeps exactly the
 * visibility its author gave it. Rewriting those would be irreversible in the
 * one direction that cannot be apologised for.
 *
 * It is a separate migration rather than an edit to the original
 * `create_sto_posts_table` on purpose: a migration that has already run never
 * runs again, so editing it would leave existing databases on the old default
 * while fresh ones got the new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_posts', function (Blueprint $table): void {
            $table->string('visibility', 32)
                ->default(PostVisibility::Private->value)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('sto_posts', function (Blueprint $table): void {
            $table->string('visibility', 32)
                ->default(PostVisibility::Public->value)
                ->change();
        });
    }
};
