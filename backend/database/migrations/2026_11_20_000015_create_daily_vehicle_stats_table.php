<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_vehicle_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->unsignedInteger('driving_seconds')->default(0);
            $table->unsignedInteger('stopped_seconds')->default(0);
            $table->unsignedSmallInteger('stops_count')->default(0);
            $table->decimal('max_speed_kmh', 5, 1)->default(0);
            $table->decimal('avg_speed_kmh', 5, 1)->default(0);
            $table->timestamps();
            $table->unique(['vehicle_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_vehicle_stats');
    }
};
