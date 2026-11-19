<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_devices', function (Blueprint $table) {
            $table->id();
            $table->string('imei', 20)->unique();
            $table->string('model', 60)->nullable();
            $table->string('sim_number', 30)->nullable();
            // Un boîtier équipe au plus un camion.
            $table->foreignId('vehicle_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->dateTime('installed_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_devices');
    }
};
