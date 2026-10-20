<?php

namespace App\Modules\POS\Database\Factories;

use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PosCashMovement> */
class PosCashMovementFactory extends Factory
{
    protected $model = PosCashMovement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'pos_session_id' => PosSession::factory(),
            'reference_number' => fake()->unique()->uuid(),
            'movement_type' => 'cash_in',
            'direction' => 'in',
            'amount' => '50.0000',
            'reason' => 'Cash float',
            'corrects_movement_id' => null,
            'created_by' => fn (array $attributes): int => PosSession::findOrFail($attributes['pos_session_id'])->opened_by,
            'approved_by' => null,
            'approved_at' => null,
            'occurred_at' => now(),
        ];
    }
}
