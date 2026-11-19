<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Driver> */
class DriverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_number' => 'CH-'.fake()->unique()->numberBetween(2000, 9999),
            'badge_number' => 'B-'.fake()->unique()->numberBetween(5000, 9999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+212 6 '.fake()->numerify('## ## ## ##'),
            'license_number' => fake()->bothify('?-######'),
            'license_category' => fake()->randomElement(['C', 'CE']),
            'license_expires_at' => now()->addYears(2),
            'status' => DriverStatus::Available,
        ];
    }
}
