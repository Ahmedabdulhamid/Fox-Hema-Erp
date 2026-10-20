<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\Products\Database\Seeders\ColorSeeder;
use App\Modules\Products\Database\Seeders\SizeSeeder;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Size;
use App\Modules\Taxes\Database\Seeders\TaxSeeder;
use App\Modules\Taxes\Models\Tax;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class CatalogSeedersTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->app->make('migrator')->path(app_path('Modules/Discounts/Database/Migrations'));
    }

    public function test_seeders_can_repeat_without_overwriting_existing_catalog_and_tax_settings(): void
    {
        $color = Color::factory()->create(['name' => 'Black', 'hex_code' => '#111111', 'sort_order' => 99, 'is_active' => false]);
        $size = Size::factory()->create(['name' => 'M', 'sort_order' => 99, 'is_active' => false]);
        $tax = Tax::factory()->create(['code' => 'ZERO_RATE', 'name' => 'Custom zero rate', 'rate' => 2, 'usage' => 'purchases', 'is_active' => true]);
        $customColor = Color::factory()->create(['name' => 'Custom color']);
        $customSize = Size::factory()->create(['name' => 'Custom size']);
        $customTax = Tax::factory()->create(['code' => 'CUSTOM_TAX']);

        $before = [$color->fresh()->getAttributes(), $size->fresh()->getAttributes(), $tax->fresh()->getAttributes()];
        $seeders = [ColorSeeder::class, SizeSeeder::class, TaxSeeder::class];
        $this->seed($seeders);
        $this->seed($seeders);

        $this->assertSame($before, [$color->fresh()->getAttributes(), $size->fresh()->getAttributes(), $tax->fresh()->getAttributes()]);
        $this->assertModelExists($customColor);
        $this->assertModelExists($customSize);
        $this->assertModelExists($customTax);
        $this->assertDatabaseCount('colors', 14);
        $this->assertDatabaseCount('sizes', 9);
        $this->assertDatabaseCount('taxes', 3);
    }

    public function test_seeders_create_catalog_defaults_and_inactive_zero_rate_without_activating_modules_or_creating_users(): void
    {
        $this->seed(AccessManagementSeeder::class);
        $before = Module::query()->pluck('is_active', 'code')->all();
        $this->seed([ColorSeeder::class, SizeSeeder::class, TaxSeeder::class]);

        $this->assertDatabaseHas('colors', ['name' => 'Blue', 'hex_code' => '#0000FF', 'is_active' => true]);
        $this->assertDatabaseHas('sizes', ['name' => 'One Size', 'sort_order' => 7, 'is_active' => true]);
        $this->assertDatabaseHas('taxes', ['code' => 'ZERO_RATE', 'rate' => 0, 'usage' => 'both', 'is_active' => false]);
        $this->assertDatabaseHas('taxes', ['code' => 'VAT_14', 'rate' => 14, 'usage' => 'both', 'is_active' => false]);
        $this->assertSame($before, Module::query()->pluck('is_active', 'code')->all());
        $this->assertDatabaseCount('users', 0);
    }
}
