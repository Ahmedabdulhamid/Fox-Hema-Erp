<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\LocationSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\Purchasing\Database\Seeders\SupplierSeeder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchasingSetupSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_seeder_creates_ten_fictional_suppliers_with_varied_terms_and_status(): void
    {
        $this->seed(SupplierSeeder::class);
        $this->assertDatabaseCount('suppliers', 10);
        $this->assertSame(8, Supplier::where('is_active', true)->count());
        $this->assertEqualsCanonicalizing([0, 7, 15, 30, 60], Supplier::distinct()->pluck('payment_terms_days')->all());
        foreach (Supplier::all() as $supplier) {
            $this->assertMatchesRegularExpression('/^EG-DEMO-\d{3}$/', $supplier->code);
            $this->assertMatchesRegularExpression('/^\+20 1[0125] \d{4} \d{4}$/', $supplier->phone);
            $this->assertMatchesRegularExpression('/@example\.(com|net|org)$/', $supplier->email);
            $this->assertStringContainsString('Egypt', $supplier->address);
            $this->assertStringContainsString('Fictional', $supplier->notes);
            $this->assertTrue(Str::isUuid($supplier->uuid));
            $this->assertNotEmpty($supplier->slug);
        }
        foreach (['purchase_orders', 'vendor_bills', 'vendor_payments', 'users'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_supplier_seeder_repeats_without_overwriting_or_deleting_existing_records(): void
    {
        $matched = Supplier::factory()->create(['code' => 'EG-DEMO-001', 'name' => 'Existing supplier', 'payment_terms_days' => 42, 'is_active' => false]);
        $custom = Supplier::factory()->create(['code' => 'CUSTOM', 'name' => 'Custom supplier']);
        $before = [$matched->fresh()->getAttributes(), $custom->fresh()->getAttributes()];
        $this->seed(SupplierSeeder::class);
        $first = Supplier::orderBy('id')->get()->toArray();
        $this->seed(SupplierSeeder::class);
        $this->assertSame($first, Supplier::orderBy('id')->get()->toArray());
        $this->assertSame($before, [$matched->fresh()->getAttributes(), $custom->fresh()->getAttributes()]);
        $this->assertDatabaseCount('suppliers', 11);
    }

    public function test_location_seeder_creates_four_locations_and_repeats_without_side_effects(): void
    {
        $this->seed(LocationSeeder::class);
        $this->assertDatabaseCount('locations', 4);
        $this->assertDatabaseHas('locations', ['slug' => 'central-warehouse', 'name' => 'Central Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        foreach ([1, 2, 3] as $branch) {
            $this->assertDatabaseHas('locations', ['slug' => 'branch-'.$branch, 'name' => 'Branch '.$branch, 'type' => 'branch', 'is_active' => true]);
        }
        foreach (Location::all() as $location) {
            $this->assertTrue(Str::isUuid($location->uuid));
            $this->assertMatchesRegularExpression($location->type === 'warehouse' ? '/^WH-\d{4,}$/' : '/^BR-\d{4,}$/', $location->code);
        }
        $before = Location::orderBy('id')->get()->toArray();
        $this->seed(LocationSeeder::class);
        $this->assertSame($before, Location::orderBy('id')->get()->toArray());
        $this->assertSame(4, Location::distinct()->count('code'));
        foreach (['users', 'roles', 'permissions', 'location_user', 'stock_movements', 'stock_balances', 'products', 'suppliers'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_location_seeder_preserves_customized_and_unrelated_locations(): void
    {
        $this->seed(LocationSeeder::class);
        $matched = Location::where('slug', 'branch-1')->firstOrFail();
        $matched->update(['name' => 'Renamed branch', 'is_active' => false, 'address' => 'Custom address']);
        $custom = Location::create(['name' => 'Additional branch', 'type' => 'branch', 'is_active' => false]);
        $before = Location::orderBy('id')->get()->toArray();
        $this->seed(LocationSeeder::class);
        $this->assertSame($before, Location::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('locations', 5);
        $this->assertModelExists($custom);
    }

    public function test_existing_location_prevents_initialization_regardless_of_name_or_slug(): void
    {
        $existing = new Location(['name' => ' Branch-2 ', 'type' => 'branch', 'is_active' => false]);
        $existing->forceFill(['slug' => 'existing-second-branch'])->save();
        $before = $existing->fresh()->getAttributes();
        $this->seed(LocationSeeder::class);
        $this->assertDatabaseCount('locations', 1);
        $this->assertSame($before, $existing->fresh()->getAttributes());
    }

    public function test_existing_location_with_a_different_type_is_preserved_without_initialization(): void
    {
        $existing = new Location(['name' => 'Central Warehouse', 'type' => 'branch']);
        $existing->forceFill(['slug' => 'central-warehouse'])->save();
        $this->seed(LocationSeeder::class);
        $this->assertDatabaseCount('locations', 1);
        $this->assertSame('branch', $existing->fresh()->type);
    }

    public function test_location_seeder_does_not_restore_a_deleted_default(): void
    {
        $this->seed(LocationSeeder::class);
        Location::where('slug', 'branch-2')->firstOrFail()->delete();
        $before = Location::orderBy('id')->get()->toArray();

        $this->seed(LocationSeeder::class);

        $this->assertSame($before, Location::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('locations', 3);
    }
}
