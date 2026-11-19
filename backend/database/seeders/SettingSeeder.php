<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Setting::DEFAULTS as $key => $value) {
            if (! Setting::whereKey($key)->exists()) {
                Setting::put($key, $value);
            }
        }
    }
}
