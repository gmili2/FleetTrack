<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->index();
            $table->string('shape', 10);                   // circle | polygon
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->nullable();
            $table->json('coordinates')->nullable();       // [[lat, lng], ...] pour un polygone
            $table->string('color', 7)->default('#1D4ED8');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('min_visit_seconds')->default(120); // anti-rebond
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};
