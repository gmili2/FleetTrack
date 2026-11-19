<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_events', function (Blueprint $table) {
            $table->id();
            $table->string('badge_number', 20)->index();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete(); // résolu depuis le badge
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 3);                     // in | out
            $table->dateTime('occurred_at')->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('place')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_events');
    }
};
