<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Stourify\Enums\ReportStatus;

/**
 * Moderation queue. Polymorphic so one flow covers spots, posts, reviews,
 * comments and users — the Report Content screen is reached from all of them.
 *
 * Reports are anonymous to the reported party but not to moderators, so
 * `user_id` is retained; the API never exposes it outside the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sto_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');

            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->string('status', 32)->default(ReportStatus::Pending->value);

            $table->foreignId('resolved_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
            $table->index(['organization_id', 'status']);
            // One open report per user per target — re-reporting is a no-op.
            $table->unique(['user_id', 'reportable_type', 'reportable_id'], 'sto_reports_unique_reporter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sto_reports');
    }
};
