<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\UsesStockConstraintSchema;
use Tests\TestCase;

class LegacyStockBalanceCorruptionTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use UsesStockConstraintSchema;

    public function test_supported_services_reject_all_legacy_corruption_without_repair_or_side_effects(): void
    {
        $this->assertStockSchemaIdentity();
        $paths = [...$this->app['migrator']->paths(), base_path('database/migrations'), base_path('tests/Fixtures/Migrations')];
        $files = $this->app['migrator']->getMigrationFiles($paths);
        $migration = pathinfo(self::STOCK_CONSTRAINT_MIGRATION, PATHINFO_FILENAME);
        $this->assertArrayHasKey($migration, $files);
        unset($files[$migration]);
        $this->artisan('migrate', ['--path' => array_values($files), '--realpath' => true, '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('migrations')->where('migration', $migration)->count());
        $this->assertSame(0, DB::table('information_schema.TABLE_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'stock_balances')->where('CONSTRAINT_TYPE', 'CHECK')->count());
        $this->seed(AccessManagementSeeder::class);
        Module::query()->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = $this->provisionTestSuperAdmin();
        $this->actingAs($actor);
        $branch = Location::create(['name' => 'Legacy balance branch', 'type' => 'branch', 'is_active' => true]);
        $product = Product::factory()->create(['status' => 'active', 'is_active' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sale_price' => '10']);
        $this->configureSalesPricing($variant, '0', '0');
        $tables = ['stock_balances', 'stock_movements', 'sales_orders', 'sales_order_items', 'sales_stock_reservations',
            'sales_reservation_consumptions', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers',
            'fifo_layer_consumptions', 'document_numbers', 'document_number_sequences'];
        $post = fn (string $quantity) => app(InventoryPostingService::class)->post(new StockPostingCommand(
            $variant, $branch, str_starts_with($quantity, '-') ? StockMovementType::Sale : StockMovementType::Purchase,
            $quantity, (string) Str::uuid7()), $actor);
        foreach (InventoryPostingTest::invalidBalances() as [$hand, $reserved, $available]) {
            $this->assertStockSchemaIdentity();
            DB::beginTransaction();
            try {
                StockBalance::create(['variant_id' => $variant->id, 'location_id' => $branch->id,
                    'quantity_on_hand' => $hand, 'quantity_reserved' => $reserved, 'quantity_available' => $available]);
                $before = $this->stockTableEvidence($tables);
                $this->rejectLegacy(fn () => $post('1'));
                $this->assertSame($before, $this->stockTableEvidence($tables));
            } finally {
                DB::rollBack();
            }
        }
        DB::beginTransaction();
        try {
            $balance = StockBalance::create(['variant_id' => $variant->id, 'location_id' => $branch->id,
                'quantity_on_hand' => '5', 'quantity_reserved' => '2', 'quantity_available' => '3']);
            $post('-3');
            $this->assertSame('2.0000', $balance->fresh()->quantity_reserved);
            $this->rejectLegacy(fn () => $post('-0.0001'));
            $balance->refresh()->update(['quantity_available' => '9']);
            $before = $this->stockTableEvidence($tables);
            $this->rejectLegacy(fn () => $post('1'));
            $this->assertSame($before, $this->stockTableEvidence($tables));
        } finally {
            DB::rollBack();
        }
        foreach ([['quantity_available' => '9'], ['quantity_reserved' => '11'], ['quantity_on_hand' => '-1']] as $bad) {
            DB::beginTransaction();
            try {
                StockBalance::create(['variant_id' => $variant->id, 'location_id' => $branch->id,
                    'quantity_on_hand' => '10', 'quantity_reserved' => '0', 'quantity_available' => '10']);
                $order = app(SalesOrderService::class)->create($actor, ['location_id' => $branch->id,
                    'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => '5', 'unit_price' => '10']]], (string) Str::uuid7());
                DB::table('stock_balances')->where('variant_id', $variant->id)->update($bad);
                $before = $this->stockTableEvidence($tables);
                $this->rejectLegacy(fn () => app(SalesOrderConfirmationService::class)->confirm($actor, $order));
                $this->assertSame($before, $this->stockTableEvidence($tables));
            } finally {
                DB::rollBack();
            }
        }
        $this->assertSame([], DB::table('stock_balances')->get()->all());
    }

    private function rejectLegacy(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Legacy corruption must still be rejected by the application service.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
