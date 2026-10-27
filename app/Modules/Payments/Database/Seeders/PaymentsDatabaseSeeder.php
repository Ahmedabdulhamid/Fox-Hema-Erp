<?php

namespace App\Modules\Payments\Database\Seeders;

use Illuminate\Database\Seeder;

class PaymentsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PaymentMethodSeeder::class);
    }
}
