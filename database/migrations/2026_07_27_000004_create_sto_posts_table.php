<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Stourify\Enums\PostVisibility;

/**
 * A photo share attached to a spot — the unit the Home Feed renders.
 *
 * A spot is the durable place; a post is one explorer's visit to it. The Spot
 * Photo Gallery aggregates media from the spot's posts, which is why there is
 * no separate spot_media table: attribution already lives on the post.
 *
 * Media and likes are attachables (HasOrganizationMedia, HasReactions);
 * comments are the core Comment model via HasComments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_posts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->nullable()
                ->constrained('sto_spots')->cascadeOnDelete();

            $table->text('caption')->nullable();
            $table->string('visibility', 32)->default(PostVisibility::Public->value);

            // Denormalized engagement counters — the feed cannot afford to count.
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);

            // Set when the client finishes uploading every queued photo. A post
            // created offline exists locally before its media does.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'visibility', 'published_at']);
            $table->index(['user_id', 'published_at']);
            $table->index(['spot_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_posts');
    }
};
