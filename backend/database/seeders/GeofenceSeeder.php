<?php

namespace Database\Seeders;

use App\Enums\GeofenceShape;
use App\Enums\GeofenceType;
use App\Models\Customer;
use App\Models\Geofence;
use App\Support\Geo;
use Illuminate\Database\Seeder;

/** 8 zones autour de Casablanca : 1 dépôt, 1 usine, 4 clients, 1 chantier, 1 zone de livraison. */
class GeofenceSeeder extends Seeder
{
    public const DEPOT = 'Dépôt Aïn Sebaâ';

    public function run(): void
    {
        $customer = fn (string $code) => Customer::where('code', $code)->value('id');

        $zones = [
            [self::DEPOT, GeofenceType::Depot, 'polygon', [[33.6046, -7.5418], [33.6049, -7.5381], [33.6022, -7.5376], [33.6018, -7.5413]], null, 'Zone industrielle Aïn Sebaâ', 180],
            ['Usine Mohammedia', GeofenceType::Factory, 'polygon', [[33.6948, -7.3925], [33.6952, -7.3878], [33.6915, -7.3872], [33.6910, -7.3920]], null, 'Zone industrielle, Mohammedia', 300],
            ['Client — Atlas Béton', GeofenceType::Customer, 'circle', [33.5318, -7.6425, 250], 'C-0142', 'Zone industrielle Sidi Maârouf', 300],
            ['Client — Maghreb Agro', GeofenceType::Customer, 'circle', [33.5596, -7.6702, 150], 'C-0098', 'Hay Hassani', 300],
            ['Client — Sahara Distribution', GeofenceType::Customer, 'circle', [33.6178, -7.5012, 200], 'C-0117', 'Sidi Bernoussi', 300],
            ['Client — Casa Matériaux', GeofenceType::Customer, 'circle', [33.5452, -7.5938, 200], 'C-0123', 'Aïn Chock', 300],
            ['Chantier Lissasfa', GeofenceType::Site, 'circle', [33.5329, -7.6818, 400], null, 'Lissasfa', 300],
            ['Zone de livraison Port', GeofenceType::DeliveryZone, 'polygon', [[33.6082, -7.6142], [33.6091, -7.6050], [33.6031, -7.6038], [33.6020, -7.6128]], null, 'Port de Casablanca', 240],
        ];

        foreach ($zones as [$name, $type, $shape, $geom, $code, $address, $minVisit]) {
            $isCircle = $shape === 'circle';
            [$lat, $lng] = $isCircle ? [$geom[0], $geom[1]] : Geo::centroid($geom);
            Geofence::updateOrCreate(['name' => $name], [
                'type' => $type,
                'shape' => $isCircle ? GeofenceShape::Circle : GeofenceShape::Polygon,
                'center_lat' => $lat,
                'center_lng' => $lng,
                'radius_m' => $isCircle ? $geom[2] : null,
                'coordinates' => $isCircle ? null : $geom,
                'color' => $type->color(),
                'customer_id' => $code ? $customer($code) : null,
                'min_visit_seconds' => $minVisit,
                'address' => $address,
                'is_active' => true,
            ]);
        }
    }
}
