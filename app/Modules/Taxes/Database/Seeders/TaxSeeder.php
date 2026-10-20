<?php

namespace App\Modules\Taxes\Database\Seeders;

use App\Modules\Taxes\Models\Tax;
use Illuminate\Database\Seeder;

class TaxSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([['code' => 'ZERO_RATE', 'name' => 'Zero rate (0%)', 'rate' => 0], ['code' => 'VAT_14', 'name' => 'VAT (14%)', 'rate' => 14]] as $tax) {
            Tax::query()->firstOrCreate(
                ['code' => $tax['code']],
                [
                    'name' => $tax['name'],
                    'type' => 'percentage',
                    'rate' => $tax['rate'],
                    'price_inclusion' => 'excluded',
                    'usage' => 'both',
                    'is_active' => false,
                ],
            );
        }
    }
}
