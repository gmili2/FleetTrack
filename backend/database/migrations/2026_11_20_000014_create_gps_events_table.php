<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('geofence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_events');
    }
};
