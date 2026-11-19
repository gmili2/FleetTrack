<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('message');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = automatique
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['mission_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_events');
    }
};
