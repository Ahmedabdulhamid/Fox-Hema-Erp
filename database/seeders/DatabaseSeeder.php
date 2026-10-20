<?php

namespace Database\Seeders;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\Products\Database\Seeders\ColorSeeder;
use App\Modules\Products\Database\Seeders\SizeSeeder;
use App\Modules\Taxes\Database\Seeders\TaxSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([AccessManagementSeeder::class, ColorSeeder::class, SizeSeeder::class]);

        if (Schema::hasTable('taxes')) {
            $this->call(TaxSeeder::class);
        } else {
            $this->command?->warn('TaxSeeder skipped: run the taxes migration in Modules/Discounts before seeding taxes.');
        }
    }
}
