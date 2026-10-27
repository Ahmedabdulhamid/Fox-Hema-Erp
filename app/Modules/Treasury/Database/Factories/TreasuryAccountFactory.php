<?php

namespace App\Modules\Treasury\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TreasuryAccount> */
class TreasuryAccountFactory extends Factory
{
    protected $model = TreasuryAccount::class;

    public function definition(): array
    {
        return [
            'code' => 'CASH-'.strtoupper(fake()->unique()->bothify('??########')),
            'name' => fake()->words(3, true), 'type' => 'branch_cashbox', 'ownership' => 'location',
            'location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'branch', 'is_active' => true])->id,
            'currency' => 'EGP', 'is_active' => true, 'created_by' => User::factory(),
            'updated_by' => fn (array $attributes): int => (int) $attributes['created_by'],
        ];
    }

    public function central(): static
    {
        return $this->state(fn (): array => ['type' => 'central_cashbox', 'ownership' => 'central', 'location_id' => null]);
    }

    public function bank(): static
    {
        return $this->state(fn (): array => ['type' => 'bank', 'ownership' => 'central', 'location_id' => null,
            'bank_name' => 'Test Bank', 'account_identifier' => 'TEST-1234']);
    }
}
