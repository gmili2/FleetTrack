<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number', 20)->unique();  // ex. 48213-A-1
            $table->string('name', 30)->unique();          // ex. CAM-07
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('type', 20)->index();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedSmallInteger('speed_limit_kmh')->default(90);
            $table->unsignedInteger('odometer_km')->default(0);
            $table->foreignId('current_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
