<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_tag_pivot', function (Blueprint $table) {
            $table->foreignId('spot_id')->constrained('spots')->cascadeOnDelete();
            $table->foreignId('spot_tag_id')->constrained('spot_tags')->cascadeOnDelete();
            $table->primary(['spot_id', 'spot_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_tag_pivot');
    }
};
