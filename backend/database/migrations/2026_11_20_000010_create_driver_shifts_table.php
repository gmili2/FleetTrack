<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete(); // null = badge inconnu
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('badge_number', 20)->nullable();
            $table->dateTime('start_at');
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->string('start_place')->nullable();
            $table->dateTime('end_at')->nullable();        // null = en service
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();
            $table->string('end_place')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->string('anomaly', 20)->nullable()->index(); // outside_depot | exit_not_badged | unknown_badge
            $table->timestamps();
            $table->index(['driver_id', 'start_at']);
            $table->index(['vehicle_id', 'start_at']);
            $table->index('start_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shifts');
    }
};
