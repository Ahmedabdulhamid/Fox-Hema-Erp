<?php

namespace App\Modules\POS\Database\Factories;

use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosSessionTender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PosSessionTender> */
class PosSessionTenderFactory extends Factory
{
    protected $model = PosSessionTender::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'pos_session_id' => PosSession::factory(),
            'method' => 'cash',
            'expected_amount' => '0.0000',
            'verified_amount' => null,
            'difference_amount' => null,
            'verified_by' => null,
            'verified_at' => null,
            'notes' => null,
        ];
    }
}
