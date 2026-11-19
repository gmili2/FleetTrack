<?php

use App\Enums\MissionStatus;
use App\Models\Driver;
use App\Models\Geofence;
use App\Models\Mission;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('crée les données de démo du cahier des charges', function () {
    expect(Vehicle::count())->toBe(12)
        ->and(Driver::count())->toBe(14)
        ->and(Geofence::count())->toBe(8)
        ->and(Vehicle::has('device')->count())->toBe(12)
        ->and(Geofence::where('type', 'customer')->count())->toBe(4);

    foreach (MissionStatus::cases() as $status) {
        expect(Mission::where('status', $status)->exists())->toBeTrue("Aucune mission « {$status->label()} »");
    }
});

it('respecte les formats CH-xxxx, B-xxxx et MIS-AAAA-00001', function () {
    expect(Driver::where('employee_number', 'CH-1012')->value('badge_number'))->toBe('B-4410');
    Driver::pluck('employee_number')->each(fn ($n) => expect($n)->toMatch('/^CH-\d{4}$/'));
    Mission::pluck('reference')->each(fn ($r) => expect($r)->toMatch('/^MIS-\d{4}-\d{5}$/'));
});

it('ne met jamais un camion ou un chauffeur sur deux missions en cours', function () {
    $inProgress = Mission::where('status', MissionStatus::InProgress)->get();
    expect($inProgress->pluck('vehicle_id')->duplicates())->toBeEmpty()
        ->and($inProgress->pluck('driver_id')->duplicates())->toBeEmpty();
});

it('génère la référence de mission suivante', function () {
    $year = now()->year;
    $last = Mission::where('reference', 'like', "MIS-$year-%")->max('reference');
    expect(Mission::nextReference($year))->toBe(sprintf('MIS-%d-%05d', $year, (int) substr($last, -5) + 1))
        ->and(Mission::nextReference(1999))->toBe('MIS-1999-00001');
});

it('gare tous les camions dans le dépôt au départ', function () {
    $depot = Geofence::where('type', 'depot')->firstOrFail();
    Vehicle::with('liveStatus')->get()->each(fn ($v) => expect($depot->contains($v->liveStatus->latitude, $v->liveStatus->longitude))->toBeTrue());
});
