<?php

namespace Database\Factories;

use App\Enums\GeofenceShape;
use App\Enums\GeofenceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Geofence> */
class GeofenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Zone '.fake()->unique()->word(),
            'type' => GeofenceType::Customer,
            'shape' => GeofenceShape::Circle,
            'center_lat' => 33.5731,
            'center_lng' => -7.5898,
            'radius_m' => 200,
            'color' => '#15803D',
            'min_visit_seconds' => 120,
            'is_active' => true,
        ];
    }

    public function polygon(array $coordinates): static
    {
        return $this->state(fn () => ['shape' => GeofenceShape::Polygon, 'coordinates' => $coordinates, 'radius_m' => null]);
    }
}
