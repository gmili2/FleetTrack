<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartes GPS : nom, opérateur de la SIM, configuration de chaque carte (protocole, serveur, APN, intervalles…),
 * et carte utilisée pour chaque service (le chauffeur choisit camion + carte au pointage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_devices', function (Blueprint $table) {
            $table->string('label', 40)->nullable()->unique()->after('id');
            $table->string('operator', 20)->nullable()->after('sim_number');
            $table->json('config')->nullable()->after('is_active');
            $table->text('notes')->nullable()->after('config');
        });
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->foreignId('gps_device_id')->nullable()->after('vehicle_id')->constrained()->nullOnDelete();
        });

        // Nouvelles permissions « devices.* » pour les rôles existants.
        if (Schema::hasTable('roles')) {
            (new RolesAndPermissionsSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('driver_shifts', fn (Blueprint $t) => $t->dropConstrainedForeignId('gps_device_id'));
        Schema::table('gps_devices', function (Blueprint $t) {
            $t->dropUnique(['label']);
            $t->dropColumn(['label', 'operator', 'config', 'notes']);
        });
    }
};
