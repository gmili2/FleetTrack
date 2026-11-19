<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_zone_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('geofence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('entered_at');
            $table->dateTime('exited_at')->nullable();     // null = visite en cours
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['geofence_id', 'entered_at']);
            $table->index(['vehicle_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_zone_visits');
    }
};
