<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\EstimatedCostResolver;
use App\Modules\Products\Filament\Resources\Products\Pages\ViewProduct;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\VariantInventoryValuation;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Filament\Pages\Profitability;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\ProfitabilityReport;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesProfitability;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class ProfitabilityTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['sales', 'products', 'inventory', 'inventory_costing', 'purchasing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        $this->withSession(['locale' => 'en']);
        app()->setLocale('en');
        app(LocationContext::class)->setFor($this->actor, null);
        $this->branch = Location::create(['name' => 'Profit branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sale_price' => '2000', 'cost_price' => '1500']);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
    }

    private function receive(string $quantity = '10', string $cost = '2', ?ProductVariant $variant = null): void
    {
        $variant ??= $this->variant;
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->branch->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $variant->product_id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($variant)['receiving_mode'],
                'variant_id' => $variant->id, 'quantity' => $quantity, 'unit_price' => $cost]]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
            'quantity' => $quantity, 'unit_cost' => $cost]]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
    }

    private function order(string $quantity = '10', string $price = '20', bool $confirm = true): SalesOrder
    {
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'items' => [['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'quantity' => $quantity, 'unit_price' => $price]]], (string) Str::uuid7());

        return $confirm ? app(SalesOrderConfirmationService::class)->confirm($this->actor, $order) : $order;
    }

    private function deliver(SalesOrder $order, string $quantity, bool $post = true): SalesDelivery
    {
        $service = app(SalesDeliveryService::class);
        $delivery = $service->create($this->actor, ['sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $order->items()->first()->id, 'quantity' => $quantity]]], (string) Str::uuid7());

        return $post ? app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $service->revision($delivery)) : $delivery;
    }

    private function report(?string $from = null, ?string $until = null, ?User $actor = null): array
    {
        $service = app(ProfitabilityReport::class);

        return $service->summary($service->events($actor ?? $this->actor, $from ?? today()->toDateString(), $until ?? today()->toDateString()));
    }

    public static function methods(): array
    {
        return [['avco'], ['fifo'], ['standard']];
    }

    #[DataProvider('methods')]
    public function test_actual_costs_partial_deliveries_discounts_vat_and_replay(string $method): void
    {
        InventoryValuationSetting::query()->update(['costing_method' => $method]);
        if ($method === 'standard') {
            $standard = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => '2']);
            InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => '2', 'effective_at' => now()->subDay()]);
        }
        $this->configureSalesPricing($this->variant, '10', '14');
        $this->receive();
        $order = $this->order();
        $estimate = app(SalesProfitability::class)->order($this->actor, $order)['summary'];
        $this->assertSame('180.0000', $estimate['revenue']);
        $this->assertSame('20.0000', $estimate['cost']);
        $draft = $this->deliver($order, '3', false);
        $this->assertSame('0.0000', $this->report()['revenue']);
        $service = app(SalesDeliveryService::class);
        $posted = app(SalesDeliveryPostingService::class)->post($this->actor, $draft, $service->revision($draft));
        $this->assertSame('54.0000', $this->report()['revenue']);
        $this->assertSame('6.0000', $this->report()['cost']);
        $this->assertSame('48.0000', $this->report()['profit']);
        app(SalesDeliveryPostingService::class)->post($this->actor, $posted, $posted->posting_revision);
        $this->assertSame('6.0000', $this->report()['cost']);
        $this->deliver($order->fresh(), '7');
        $this->assertSame('180.0000', $this->report()['revenue']);
        $this->assertSame('20.0000', $this->report()['cost']);
        $this->assertSame('0.0000', DB::table('inventory_cost_states')->where('variant_id', $this->variant->id)->value('total_value'));
    }

    public function test_fifo_estimates_share_layers_across_lines_without_writing_inventory(): void
    {
        $this->receive('2', '2');
        $this->receive('3', '5');
        $before = DB::table('fifo_layers')->get()->toJson();
        $lines = app(EstimatedCostResolver::class)->lines([
            ['variant_id' => $this->variant->id, 'quantity' => '2'], ['variant_id' => $this->variant->id, 'quantity' => '2'],
        ], $this->branch->id);
        $this->assertSame('4.0000', $lines[0]['cost']);
        $this->assertSame('10.0000', $lines[1]['cost']);
        $this->assertSame($before, DB::table('fifo_layers')->get()->toJson());
    }

    public function test_estimates_are_location_and_variant_specific_and_missing_cost_is_unavailable(): void
    {
        $this->receive('10', '2');
        $other = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $this->receive('10', '7', $other);
        $lines = [['variant_id' => $this->variant->id, 'quantity' => '1'], ['variant_id' => $other->id, 'quantity' => '1']];
        $resolver = app(EstimatedCostResolver::class);
        $this->assertSame(['2.0000', '7.0000'], array_column($resolver->lines($lines, $this->branch->id), 'cost'));
        $this->branch = Location::create(['name' => 'Other estimate branch', 'type' => 'branch', 'is_active' => true]);
        $this->assertNull($resolver->lines($lines, $this->branch->id)[0]['cost']);
        $this->receive('10', '9');
        $this->assertSame('9.0000', $resolver->lines($lines, $this->branch->id)[0]['cost']);
        $this->assertNull($resolver->lines($lines, $this->branch->id)[1]['cost']);
    }

    public function test_confirmation_freezes_estimate_and_keeps_commercial_snapshot_intact(): void
    {
        $this->receive();
        $draft = $this->order(confirm: false);
        $pricing = $draft->items()->first()->commercial_snapshot;
        $confirmed = app(SalesOrderConfirmationService::class)->confirm($this->actor, $draft);
        $snapshot = $confirmed->items()->first()->profitability_snapshot;
        $this->variant->update(['cost_price' => '999', 'sale_price' => '9999']);
        $this->receive('10', '900');
        $replayed = app(SalesOrderConfirmationService::class)->confirm($this->actor, $confirmed);
        $this->assertSame($snapshot, $replayed->items()->first()->profitability_snapshot);
        $this->assertSame($pricing, $replayed->items()->first()->commercial_snapshot);
        $this->assertSame('20.0000', app(SalesProfitability::class)->order($this->actor, $replayed)['summary']['cost']);
        $this->assertArrayNotHasKey('profitability_snapshot', $replayed->items()->first()->toArray());
        $this->deliver($replayed, '10');
        $realized = $this->report();
        $this->variant->update(['cost_price' => '1', 'sale_price' => '0']);
        $this->assertSame('20.0000', $realized['cost']);
        $this->assertSame($realized, $this->report());
    }

    public function test_migration_can_reverse_empty_schema_but_preserves_historical_estimates(): void
    {
        $migration = require app_path('Modules/Sales/Database/Migrations/2026_10_21_193814_add_profitability_snapshot_to_sales_order_items.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('sales_order_items', 'profitability_snapshot'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('sales_order_items', 'profitability_snapshot'));
        $this->receive();
        $this->order();
        $this->expectException(\LogicException::class);
        $migration->down();
    }

    public function test_historical_orders_without_snapshot_do_not_use_current_cost(): void
    {
        $this->receive();
        $order = $this->order();
        DB::table('sales_order_items')->where('sales_order_id', $order->id)->update(['profitability_snapshot' => null]);
        $this->assertNull(app(SalesProfitability::class)->order($this->actor, $order)['summary']['profit']);
    }

    public function test_missing_or_inconsistent_cost_evidence_never_becomes_zero_cost(): void
    {
        $this->receive();
        $this->deliver($this->order(), '10');
        DB::table('fifo_layer_consumptions')->delete();
        $summary = $this->report();
        $this->assertSame('200.0000', $summary['revenue']);
        $this->assertSame(1, $summary['missing']);
        $this->assertNull($summary['cost']);
        $this->assertNull($summary['profit']);
        DB::table('inventory_cost_entries')->where('entry_type', 'issue')->delete();
        $this->assertNull($this->report()['cost']);
    }

    public static function lowPrices(): array
    {
        return [['0', '-20.0000', null], ['1', '-10.0000', '-100.00']];
    }

    #[DataProvider('lowPrices')]
    public function test_free_and_loss_making_sales_use_historical_cogs(string $price, string $profit, ?string $margin): void
    {
        $this->receive();
        $this->deliver($this->order('10', $price), '10');
        $summary = $this->report();
        $this->assertSame('20.0000', $summary['cost']);
        $this->assertSame($profit, $summary['profit']);
        $this->assertSame($margin, $summary['margin']);
    }

    public function test_unreconciled_estimates_do_not_change_confirmation_rules(): void
    {
        $this->receive();
        DB::table('inventory_cost_states')->where('variant_id', $this->variant->id)->update(['total_value' => '500']);
        $order = $this->order();
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->items()->first()->profitability_snapshot['cost']);
        $this->assertNull(app(SalesProfitability::class)->order($this->actor, $order)['summary']['profit']);
    }

    public function test_cancelled_drafts_do_not_recognize_profit(): void
    {
        $this->receive();
        $order = $this->order(confirm: false);
        app(SalesOrderConfirmationService::class)->cancel($this->actor, $order);
        $this->assertSame('0.0000', $this->report()['revenue']);
        $this->assertSame('0.0000', $this->report()['cost']);
    }

    public function test_rounding_residuals_reconcile_across_date_ranges_and_posting_order(): void
    {
        $this->travelTo(now()->startOfDay());
        $start = today()->toDateString();
        $this->receive('3', '0.000001');
        $order = $this->order('3', '0.000050');
        $olderDraft = $this->deliver($order, '1', false);
        $this->deliver($order, '1');
        $first = $this->report();
        $this->travel(1)->days();
        app(SalesDeliveryPostingService::class)->post($this->actor, $olderDraft, app(SalesDeliveryService::class)->revision($olderDraft));
        $this->deliver($order->fresh(), '1');
        $this->assertSame($first, $this->report($start, $start));
        $this->assertSame('0.0002', $this->report($start, today()->toDateString())['revenue']);
        $this->travelBack();
    }

    private function scoped(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($user, $this->branch->id);
        $role = Role::create(['name' => 'profit-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_authorization_and_location_isolation_apply_to_reports_and_estimates(): void
    {
        $this->receive();
        $order = $this->order();
        $this->deliver($order, '10');
        $firstBranch = $this->branch;
        $this->branch = Location::create(['name' => 'Other report branch', 'type' => 'branch', 'is_active' => true]);
        $this->receive('10', '7');
        $this->deliver($this->order(), '10');
        $reader = $this->scoped([...SalesPermissions::all(), InventoryPermissions::COST]);
        $this->assertSame('70.0000', $this->report(actor: $reader)['cost']);
        $this->assertFalse(app(SalesProfitability::class)->canView($reader, $firstBranch->id));
        Livewire::test(Profitability::class)->assertSuccessful()->assertSee('EGP 70.00')->set('locationId', $firstBranch->id)->assertForbidden();
    }

    public function test_cost_information_is_hidden_from_sales_only_users(): void
    {
        $this->receive();
        $order = $this->order();
        $reader = $this->scoped(SalesPermissions::all());
        $this->assertFalse(app(SalesProfitability::class)->canView($reader));
        Livewire::test(Profitability::class)->assertForbidden();
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertSuccessful()->assertDontSee('Estimated profitability');
        Livewire::test(CreateSalesOrder::class)->assertSuccessful()->assertDontSee('Estimated profitability');
    }

    public function test_product_and_sales_screens_show_estimates_and_report_groups_reconcile(): void
    {
        $this->receive();
        $order = $this->order();
        Livewire::test(ViewProduct::class, ['record' => $this->variant->product->slug])->assertSuccessful()->assertSee('EGP 500.00')->assertSee('25.00%')->assertSee('33.33%');
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertSuccessful()->assertSee('Estimated profitability')->assertSee('EGP 180.00');
        Livewire::test(CreateSalesOrder::class)->fillForm(['location_id' => $this->branch->id, 'items' => [[
            'product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id, 'quantity' => '2', 'unit_price' => '20',
        ]]])->assertSee('EGP 36.00');
        $this->deliver($order, '10');
        $service = app(ProfitabilityReport::class);
        $events = $service->events($this->actor, today()->toDateString(), today()->toDateString());
        foreach (['product', 'variant', 'location', 'order'] as $group) {
            $rows = $service->grouped($events, $group)->get();
            $this->assertCount(1, $rows);
            $this->assertSame('200.0000', (string) $rows[0]->revenue);
            $this->assertSame('20.0000', (string) $rows[0]->cost);
        }
        Livewire::test(Profitability::class)->assertSuccessful()->assertSee('EGP 180.00');
        $valuation = app(VariantInventoryValuation::class)->read($this->actor, $this->variant, $this->branch->id);
        $this->assertNull($valuation['profitability']['cost']);
    }

    public static function dispositions(): array
    {
        return [['restock', 'fifo'], ['damaged', 'fifo'], ['scrap', 'fifo'], ['restock', 'avco'], ['restock', 'standard']];
    }

    #[DataProvider('dispositions')]
    public function test_returns_and_credits_reverse_profit_once_and_keep_losses_separate(string $disposition, string $method): void
    {
        $this->travelTo(now()->startOfDay());
        $start = today()->toDateString();
        InventoryValuationSetting::query()->update(['costing_method' => $method]);
        if ($method === 'standard') {
            $standard = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => '2']);
            InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => '2', 'effective_at' => now()->subDay()]);
        }
        $this->configureSalesPricing($this->variant, '10', '14');
        $this->receive();
        $delivery = $this->deliver($this->order(), '10');
        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'invoice_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $delivery->items()->first()->id, 'quantity' => '10']]], (string) Str::uuid7());
        $invoice = $invoices->post($this->actor, $invoice, $invoices->revision($invoice));
        $this->assertSame('180.0000', $this->report()['revenue']);
        if ($method === 'standard') {
            InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => '3', 'effective_at' => now()]);
        }
        $returns = app(SalesReturnService::class);
        $return = $returns->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => today()->toDateString(), 'reason' => 'Returned items',
            'items' => [['sales_delivery_item_id' => $delivery->items()->first()->id, 'quantity' => '2', 'disposition' => $disposition, 'notes' => 'Damaged return accepted']]], (string) Str::uuid7());
        $approver = $this->scoped(SalesPermissions::all());
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $returns->revision($return));
        app(LocationContext::class)->setFor($this->actor, null);
        $this->actingAs($this->actor);
        $return = app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));
        $summary = $this->report();
        $this->assertSame('144.0000', $summary['revenue']);
        $this->assertSame('16.0000', $summary['cost']);
        $this->assertSame('128.0000', $summary['profit']);
        $this->assertSame($disposition === 'scrap' ? '4.0000' : '0.0000', $summary['return_loss']);
        $this->assertSame($method === 'standard' ? '-2.0000' : '0.0000', $summary['return_variance']);
        $this->travel(1)->days();
        $credits = app(SalesCreditNoteService::class);
        foreach ([1, 2] as $part) {
            $note = $credits->create($this->actor, ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id, 'credit_date' => today()->toDateString(),
                'items' => [['sales_return_posted_allocation_id' => $return->postedAllocations()->first()->id, 'quantity' => '1']]], (string) Str::uuid7());
            $note = $credits->post($this->actor, $note, $credits->revision($note));
            $credits->post($this->actor, $note, $note->posting_revision);
        }
        $this->assertSame($summary, $this->report($start, today()->toDateString()));
        $this->assertSame($summary, $this->report($start, $start));
        $this->assertSame('0.0000', $this->report()['revenue']);
        $this->assertSame('0.0000', $this->report()['cost']);
        $this->travelBack();
    }
}
