<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Espace chauffeur : compte utilisateur lié à un chauffeur, origine des pointages (lecteur de badge ou application). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
        });
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->string('source', 10)->default('badge')->after('badge_number'); // badge | app
        });
        Schema::table('badge_events', function (Blueprint $table) {
            $table->string('source', 10)->default('badge')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('badge_events', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('driver_shifts', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('driver_id');
        });
    }
};
