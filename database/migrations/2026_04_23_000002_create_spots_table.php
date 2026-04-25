<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spots', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('category_id')->nullable()->constrained('spot_categories')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address')->nullable();
            $table->enum('status', ['active', 'pending'])->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'latitude', 'longitude']);
            $table->index(['latitude', 'longitude']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spots');
    }
};
