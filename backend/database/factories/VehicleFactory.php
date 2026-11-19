<?php

namespace Database\Factories;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Vehicle> */
class VehicleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plate_number' => fake()->unique()->numerify('#####-A-1'),
            'name' => 'CAM-'.fake()->unique()->numberBetween(30, 999),
            'brand' => 'Volvo',
            'model' => 'FH 460',
            'type' => VehicleType::Tractor,
            'year' => 2022,
            'status' => VehicleStatus::Active,
            'speed_limit_kmh' => 90,
        ];
    }
}
