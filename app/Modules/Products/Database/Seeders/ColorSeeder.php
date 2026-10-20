<?php

namespace App\Modules\Products\Database\Seeders;

use App\Modules\Products\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $colors = [
            'Black' => '#000000',
            'White' => '#FFFFFF',
            'Gray' => '#808080',
            'Red' => '#FF0000',
            'Blue' => '#0000FF',
            'Navy' => '#000080',
            'Green' => '#008000',
            'Yellow' => '#FFFF00',
            'Orange' => '#FFA500',
            'Pink' => '#FFC0CB',
            'Purple' => '#800080',
            'Brown' => '#A52A2A',
            'Beige' => '#F5F5DC',
        ];

        foreach ($colors as $name => $hexCode) {
            Color::query()->firstOrCreate(
                ['name' => $name],
                ['hex_code' => $hexCode, 'sort_order' => array_search($name, array_keys($colors), true), 'is_active' => true],
            );
        }
    }
}
