<?php

namespace App\Modules\Products\Database\Seeders;

use App\Modules\AccessManagement\Services\ModuleRegistry;
use Illuminate\Database\Seeder;

class ProductImportSeeder extends Seeder
{
    /** Register permissions through the existing registry; never create operational imports or grant retail roles. */
    public function run(): void
    {
        app(ModuleRegistry::class)->register();
    }
}
