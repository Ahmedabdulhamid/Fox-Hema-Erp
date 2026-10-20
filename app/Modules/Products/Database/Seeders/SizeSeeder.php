<?php

namespace App\Modules\Products\Database\Seeders;

use App\Modules\Products\Models\Size;
use Illuminate\Database\Seeder;

class SizeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'One Size'] as $sortOrder => $name) {
            Size::query()->firstOrCreate(
                ['name' => $name],
                ['sort_order' => $sortOrder, 'is_active' => true],
            );
        }
    }
}
