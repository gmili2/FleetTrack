<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_live_status', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_kmh', 5, 1)->default(0);
            $table->unsignedSmallInteger('course')->default(0);
            $table->boolean('ignition')->default(false);
            $table->string('status', 10)->default('offline')->index(); // moving | stopped | offline
            $table->dateTime('status_since')->nullable();
            $table->string('address')->nullable();
            $table->foreignId('geofence_id')->nullable()->constrained()->nullOnDelete(); // zone actuelle
            $table->decimal('today_distance_km', 8, 2)->default(0);
            $table->dateTime('last_position_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_live_status');
    }
};
