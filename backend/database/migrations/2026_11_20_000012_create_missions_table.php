<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();     // MIS-AAAA-00001
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('origin_geofence_id')->nullable()->constrained('geofences')->nullOnDelete();
            $table->foreignId('destination_geofence_id')->nullable()->constrained('geofences')->nullOnDelete();
            $table->dateTime('planned_start_at');
            $table->dateTime('planned_end_at');
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('cargo')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vehicle_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index('planned_start_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
