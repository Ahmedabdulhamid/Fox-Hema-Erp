<?php

namespace App\Modules\POS\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\POS\Models\PosDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PosDevice> */
class PosDeviceFactory extends Factory
{
    protected $model = PosDevice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'device_code' => fake()->unique()->uuid(),
            'name' => fake()->words(3, true),
            'location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'branch'])->getKey(),
            'is_active' => true,
            'registered_at' => null,
            'registered_by' => null,
            'last_seen_at' => null,
            'revoked_at' => null,
        ];
    }
}
