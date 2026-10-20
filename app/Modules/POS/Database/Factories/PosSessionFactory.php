<?php

namespace App\Modules\POS\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PosSession> */
class PosSessionFactory extends Factory
{
    protected $model = PosSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'pos_device_id' => PosDevice::factory(),
            'location_id' => fn (array $attributes): int => PosDevice::findOrFail($attributes['pos_device_id'])->location_id,
            'session_number' => fake()->unique()->uuid(),
            'opened_by' => User::factory(),
            'closed_by' => null,
            'status' => 'open',
            'opened_at' => now(),
            'closing_started_at' => null,
            'closed_at' => null,
            'opening_cash' => '0.0000',
            'expected_cash' => null,
            'counted_cash' => null,
            'cash_difference' => null,
            'cash_difference_reason' => null,
            'closing_approved_by' => null,
            'closing_approved_at' => null,
            'notes' => null,
        ];
    }
}
