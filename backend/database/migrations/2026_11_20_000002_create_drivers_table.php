<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 20)->unique(); // ex. CH-1012
            $table->string('badge_number', 20)->unique();    // ex. B-4410
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('phone', 30)->nullable();
            $table->string('license_number', 40)->nullable();
            $table->string('license_category', 10)->nullable();
            $table->date('license_expires_at')->nullable();
            $table->string('status', 20)->default('available')->index();
            $table->date('hired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
