<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\UnallocatedVariantService;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ListSalesDeliveries;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ViewSalesDelivery;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReservationConsumption;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesDeliveryPostingTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesDeliveryService $drafts;

    private SalesDeliveryPostingService $posting;

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
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['sales', 'products', 'inventory', 'inventory_costing', 'purchasing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->branch = Location::create(['name' => 'Posting branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => '999']);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $this->drafts = app(SalesDeliveryService::class);
        $this->posting = app(SalesDeliveryPostingService::class);
    }

    private function receive(string $quantity = '10', string $cost = '2', ?ProductVariant $variant = null, bool $post = true): PurchaseReceipt
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

        return $post ? $receipts->post($this->actor, $receipt, $receipts->revision($receipt)) : $receipt;
    }

    private function order(array $quantities = ['10']): SalesOrder
    {
        if (! StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->exists()) {
            $this->receive();
        }
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'items' => array_map(fn ($quantity): array => ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'quantity' => $quantity, 'unit_price' => '20', 'discount_amount' => '0', 'tax_amount' => '0'], $quantities)], (string) Str::uuid7());

        return app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
    }

    private function delivery(SalesOrder $order, ?array $quantities = null): SalesDelivery
    {
        return $this->drafts->create($this->actor, ['sales_order_id' => $order->id,
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item, $index): array => [
                'sales_order_item_id' => $item->id, 'quantity' => $quantities[$index] ?? $item->quantity])->all()], (string) Str::uuid7());
    }

    private function postDelivery(SalesDelivery $delivery): SalesDelivery
    {
        return $this->posting->post($this->actor, $delivery, $this->drafts->revision($delivery));
    }

    private function balance(): StockBalance
    {
        return StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->firstOrFail();
    }

    private function state(): InventoryCostState
    {
        return InventoryCostState::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->firstOrFail();
    }

    private function method(string $method): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => $method]);
    }

    private function standard(string $cost, ?string $effective = null): InventoryStandardCostRevision
    {
        $standard = InventoryStandardCost::firstOrCreate(['variant_id' => $this->variant->id], ['standard_cost' => $cost]);

        return InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id,
            'new_cost' => $cost, 'effective_at' => $effective ?? now()->subDay()->format('Y-m-d H:i:s')]);
    }

    private function snapshot(): array
    {
        return collect(['sales_orders', 'sales_deliveries', 'sales_delivery_items', 'sales_stock_reservations', 'sales_reservation_consumptions',
            'stock_balances', 'stock_movements', 'inventory_cost_entries', 'inventory_cost_states', 'fifo_layers', 'fifo_layer_consumptions'])
            ->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught, $caught->getMessage());

            return;
        }
        $this->fail('Expected rejection.');
    }

    private function issue(SalesDelivery $delivery): InventoryCostEntry
    {
        return $delivery->fresh()->items()->firstOrFail()->stockMovement->inventoryCostEntry;
    }

    public function test_full_delivery_posts_owned_stock_cost_and_immutable_trace_once(): void
    {
        $order = $this->order();
        $delivery = $this->postDelivery($this->delivery($order));
        $this->assertSame('posted', $delivery->status);
        $this->assertNotNull($delivery->posting_payload_hash);
        $this->assertNotNull($delivery->posting_evidence_hash);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status);
        $this->assertSame('0.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('0.0000', $this->balance()->quantity_reserved);
        $this->assertSame('0.0000', $this->balance()->quantity_available);
        $this->assertSame('0.0000', $this->state()->total_value);
        $this->assertSame('0.000000', $this->state()->avg_cost);
        $reservation = $order->reservations()->firstOrFail();
        $this->assertSame('10.0000', $reservation->quantity_reserved);
        $this->assertSame('10.0000', $reservation->quantity_consumed);
        $this->assertSame('0.0000', $reservation->quantity_released);
        $item = $delivery->items->first();
        $event = $item->reservationConsumption;
        $this->assertSame($reservation->id, $event->sales_stock_reservation_id);
        $this->assertSame($this->actor->id, $event->consumed_by);
        $this->assertSame('10.0000', $event->quantity);
        $this->assertSame(StockMovementType::Sale, $item->stockMovement->type);
        $this->assertSame($item->id, $item->stockMovement->reference_id);
        $this->assertSame('-10.0000', $item->stockMovement->quantity);
        $this->assertSame('-20.0000', $this->issue($delivery)->total_cost);
        $this->assertSame('issue', $this->issue($delivery)->entry_type);
        $this->assertDatabaseCount('sales_reservation_consumptions', 1);
    }

    public function test_partial_then_multiple_deliveries_preserve_remaining_reservation_and_free_availability(): void
    {
        $order = $this->order(['8']);
        $first = $this->postDelivery($this->delivery($order, ['3']));
        $this->assertSame('partially_fulfilled', $order->fresh()->status);
        $this->assertSame('7.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('5.0000', $this->balance()->quantity_reserved);
        $this->assertSame('2.0000', $this->balance()->quantity_available);
        $second = $this->postDelivery($this->delivery($order->fresh(), ['5']));
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('2.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('2.0000', $this->balance()->quantity_available);
        $this->assertSame('-6.0000', $this->issue($first)->total_cost);
        $this->assertSame('-10.0000', $this->issue($second)->total_cost);
    }

    public function test_duplicate_variants_on_different_order_lines_share_valuation_but_keep_line_limits(): void
    {
        $order = $this->order(['4', '6']);
        $delivery = $this->postDelivery($this->delivery($order));
        $this->assertCount(2, $delivery->items);
        $this->assertDatabaseCount('sales_reservation_consumptions', 2);
        $this->assertSame(1, $order->reservations()->count());
        $this->assertSame('10.0000', $order->reservations()->first()->quantity_consumed);
        $this->assertSame('-20.0000', (string) BigDecimal::of(InventoryCostEntry::where('entry_type', 'issue')->sum('total_cost'))->toScale(4));
    }

    public function test_fifo_spans_layers_and_preserves_original_provenance(): void
    {
        $this->receive('3', '1.111111');
        $this->receive('7', '3.123456');
        $layers = FifoLayer::orderBy('id')->get();
        $original = $layers->map->only(['id', 'quantity', 'unit_cost', 'received_at', 'source_layer_id'])->all();
        $delivery = $this->postDelivery($this->delivery($this->order(), ['5']));
        $entry = $this->issue($delivery);
        $this->assertSame('-9.5802', $entry->total_cost);
        $this->assertCount(2, $entry->fifoLayerConsumptions);
        $this->assertSame(['3.0000', '2.0000'], $entry->fifoLayerConsumptions->pluck('quantity')->all());
        $this->assertSame(['0.0000', '5.0000'], FifoLayer::orderBy('id')->pluck('remaining_qty')->all());
        $this->assertEquals($original, FifoLayer::orderBy('id')->get()->map->only(['id', 'quantity', 'unit_cost', 'received_at', 'source_layer_id'])->all());
        $this->assertSame($layers->modelKeys(), $entry->fifoLayerConsumptions->pluck('fifo_layer_id')->all());
    }

    public function test_fifo_rounding_differences_are_assigned_by_layer_carrying_value_reduction(): void
    {
        $this->receive('3', '0.333333');
        $order = $this->order(['3']);
        $costs = [];
        foreach (range(1, 3) as $index) {
            $costs[] = $this->issue($this->postDelivery($this->delivery($order->fresh(), ['1'])))->total_cost;
        }
        $this->assertSame(['-0.3333', '-0.3334', '-0.3333'], $costs);
        $this->assertSame('0.0000', $this->state()->total_value);
        $this->assertSame('0.0000', FifoLayer::first()->remaining_qty);
    }

    public function test_avco_uses_carrying_value_and_assigns_final_residual(): void
    {
        $this->method('avco');
        $this->receive('3', '0.333333');
        $order = $this->order(['3']);
        InventoryCostState::whereKey($this->state()->id)->update(['avg_cost' => '0.333300']);
        $costs = [];
        foreach (range(1, 3) as $index) {
            $costs[] = $this->issue($this->postDelivery($this->delivery($order->fresh(), ['1'])))->total_cost;
        }
        $this->assertSame(['-0.3333', '-0.3334', '-0.3333'], $costs);
        $this->assertSame('0.0000', $this->state()->total_value);
        $this->assertSame('0.000000', $this->state()->avg_cost);
        $this->assertSame(0, FifoLayerConsumption::count());
    }

    public function test_standard_uses_effective_revision_and_preserves_revision_audit(): void
    {
        $this->method('standard');
        $revision = $this->standard('2');
        $this->standard('9', now()->addDay()->format('Y-m-d H:i:s'));
        $delivery = $this->postDelivery($this->delivery($this->order(), ['4']));
        $entry = $this->issue($delivery);
        $this->assertSame($revision->id, $entry->standard_cost_revision_id);
        $this->assertSame('2.000000', $entry->unit_cost);
        $this->assertSame('-8.0000', $entry->total_cost);
        $this->assertSame('12.0000', $this->state()->total_value);
        $this->reject(fn () => $revision->update(['new_cost' => '3']));
        $this->reject(fn () => $revision->fresh()->forceFill(['new_cost' => '3'])->saveQuietly());
        $this->reject(fn () => $revision->fresh()->incrementQuietly('new_cost'));
    }

    public function test_standard_mismatch_rejects_without_revaluing_or_consuming(): void
    {
        $this->method('standard');
        $this->standard('2');
        $order = $this->order();
        $delivery = $this->delivery($order);
        $this->standard('3', now()->subMinute()->format('Y-m-d H:i:s'));
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_standard_hardening_repeated_receipts_allow_partial_full_delivery_and_replay(): void
    {
        $this->method('standard');
        $revision = $this->standard('0.333333');
        foreach (['1', '1', '1'] as $quantity) {
            $this->receive($quantity, '0.333333');
        }
        $order = $this->order(['3']);
        $partial = $this->postDelivery($this->delivery($order, ['1']));
        $this->assertSame('2.0000', $this->state()->on_hand_qty);
        $this->assertSame('0.6667', $this->state()->total_value);
        $this->assertSame('-0.3333', $this->issue($partial)->total_cost);
        $this->assertSame($revision->id, $this->issue($partial)->standard_cost_revision_id);
        $full = $this->postDelivery($this->delivery($order, ['2']));
        $this->assertSame('-0.6667', $this->issue($full)->total_cost);
        $this->assertSame('0.0000', $this->state()->on_hand_qty);
        $this->assertSame('0.0000', $this->state()->total_value);
        $this->assertSame('0.000000', $this->state()->avg_cost);
        $before = $this->snapshot();
        $this->postDelivery($partial);
        $this->postDelivery($full);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('0.0000', (string) BigDecimal::of(InventoryCostEntry::sum('total_cost'))->toScale(4));
    }

    public function test_standard_hardening_new_revision_is_allowed_after_full_depletion(): void
    {
        $this->method('standard');
        $this->standard('0.333333');
        $this->receive('3', '0.333333');
        $delivery = $this->postDelivery($this->delivery($this->order(['3'])));
        $original = $this->issue($delivery)->getAttributes();
        $current = $this->standard('0.666667', now()->subHour()->format('Y-m-d H:i:s'));
        $this->receive('1', '0.666667');
        $this->assertSame('0.6667', $this->state()->total_value);
        $this->assertSame($current->id, InventoryCostEntry::orderByDesc('id')->firstOrFail()->standard_cost_revision_id);
        $this->assertSame($original, $this->issue($delivery)->getAttributes());
    }

    public function test_standard_hardening_concurrent_issues_reconcile_fractional_residuals(): void
    {
        $this->method('standard');
        $this->standard('0.333333');
        foreach (['1', '1', '1'] as $quantity) {
            $this->receive($quantity, '0.333333');
        }
        $deliveries = [];
        foreach (['1', '1', '1'] as $quantity) {
            $deliveries[] = $this->delivery($this->order([$quantity]));
        }
        $results = $this->workers($deliveries);
        $this->assertCount(0, array_filter($results, fn ($row): bool => isset($row['rejected'])));
        $this->assertSame('0.0000', $this->state()->on_hand_qty);
        $this->assertSame('0.0000', $this->state()->total_value);
        $this->assertSame('0.0000', $this->balance()->quantity_reserved);
        $this->assertSame('0.0000', (string) BigDecimal::of(InventoryCostEntry::sum('total_cost'))->toScale(4));
    }

    public function test_standard_hardening_concurrent_receipt_and_issue_preserve_the_committed_target(): void
    {
        $this->method('standard');
        $this->standard('0.333333');
        $this->receive('1', '0.333333');
        $this->receive('1', '0.333333');
        $delivery = $this->delivery($this->order(['1']));
        $receipt = $this->receive('1', '0.333333', post: false);
        $results = $this->workers([$receipt, $delivery]);
        $this->assertSame('posted', $results[0]['status']);
        $this->assertSame($delivery->id, $results[1]['id']);
        $this->assertSame('posted', $receipt->fresh()->status);
        $this->assertSame('posted', $delivery->fresh()->status);
        $this->assertSame('2.0000', $this->state()->on_hand_qty);
        $this->assertSame('0.6667', $this->state()->total_value);
        $this->assertSame('0.0000', $this->balance()->quantity_reserved);
        $this->assertSame('0.6667', (string) BigDecimal::of(InventoryCostEntry::sum('total_cost'))->toScale(4));
        $this->assertDatabaseCount('inventory_cost_entries', 4);
        $entry = $receipt->fresh()->items()->firstOrFail()->stockMovement->inventoryCostEntry;
        $this->assertSame((string) BigDecimal::of($entry->purchase_value)->minus($entry->total_cost), $entry->purchase_price_variance);
    }

    public function test_replay_verifies_evidence_without_repricing_or_duplicate_writes(): void
    {
        $order = $this->order();
        $draft = $this->delivery($order, ['4']);
        $revision = $this->drafts->revision($draft);
        $posted = $this->postDelivery($draft);
        $before = $this->snapshot();
        $this->assertSame($posted->id, $this->posting->post($this->actor, $draft, $revision)->id);
        $this->assertSame($before, $this->snapshot());
        $this->postDelivery($this->delivery($order->fresh(), ['6']));
        $after = $this->snapshot();
        $this->posting->post($this->actor, $posted, $revision);
        $this->assertSame($after, $this->snapshot());
    }

    private function scoped(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($user, $this->branch->id);
        $role = Role::create(['name' => 'poster-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_authorized_different_actor_replay_preserves_original_poster(): void
    {
        $draft = $this->delivery($this->order());
        $revision = $this->drafts->revision($draft);
        $posted = $this->postDelivery($draft);
        $other = $this->scoped([SalesPermissions::DELIVERIES_VIEW, SalesPermissions::DELIVERIES_POST, InventoryPermissions::POST]);
        $before = $this->snapshot();
        $replayed = $this->posting->post($other, $posted, $revision);
        $this->assertSame($this->actor->id, $replayed->posted_by);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_conflicting_document_or_missing_evidence_is_never_a_successful_replay(): void
    {
        $draft = $this->delivery($this->order());
        $revision = $this->drafts->revision($draft);
        $posted = $this->postDelivery($draft);
        DB::table('sales_delivery_items')->where('sales_delivery_id', $posted->id)->update(['quantity' => '9']);
        $before = $this->snapshot();
        $this->reject(fn () => $this->posting->post($this->actor, $posted, $revision));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_tampered_cost_or_consumption_or_movement_evidence_rejects_replay(): void
    {
        $draft = $this->delivery($this->order());
        $revision = $this->drafts->revision($draft);
        $posted = $this->postDelivery($draft);
        $entry = $this->issue($posted);
        DB::table('inventory_cost_entries')->where('id', $entry->id)->update(['total_cost' => '-19']);
        $this->reject(fn () => $this->posting->post($this->actor, $posted, $revision));
        DB::table('inventory_cost_entries')->where('id', $entry->id)->update(['total_cost' => '-20']);
        DB::table('stock_movements')->where('id', $entry->stock_movement_id)->update(['notes' => 'tampered']);
        $this->reject(fn () => $this->posting->post($this->actor, $posted, $revision));
        DB::table('stock_movements')->where('id', $entry->stock_movement_id)->update(['notes' => null]);
        DB::table('sales_reservation_consumptions')->where('sales_delivery_item_id', $posted->items->first()->id)->update(['quantity' => '9']);
        $this->reject(fn () => $this->posting->post($this->actor, $posted, $revision));
    }

    public function test_stale_draft_and_overdelivery_after_another_delivery_are_rejected(): void
    {
        $order = $this->order();
        $first = $this->delivery($order, ['7']);
        $second = $this->delivery($order, ['7']);
        $this->postDelivery($first);
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($second));
        $this->assertSame($before, $this->snapshot());
        $third = $this->delivery($order->fresh(), ['2']);
        $revision = $this->drafts->revision($third);
        $this->drafts->update($this->actor, $third, ['notes' => 'changed', 'items' => [
            ['sales_order_item_id' => $order->items->first()->id, 'quantity' => '1']]], $revision);
        $this->reject(fn () => $this->posting->post($this->actor, $third, $revision));
    }

    public function test_free_stock_cannot_replace_missing_owned_reservation(): void
    {
        $order = $this->order(['5']);
        $delivery = $this->delivery($order);
        DB::table('sales_stock_reservations')->where('sales_order_id', $order->id)->update(['quantity_released' => '5']);
        $this->balance()->update(['quantity_reserved' => '0', 'quantity_available' => '10']);
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_other_orders_and_preexisting_unowned_holds_are_preserved(): void
    {
        $this->receive();
        $this->balance()->update(['quantity_reserved' => '2', 'quantity_available' => '8']);
        $first = $this->order(['4']);
        $second = $this->order(['4']);
        $this->postDelivery($this->delivery($first));
        $this->assertSame('6.0000', $this->balance()->quantity_reserved);
        $this->assertSame('0.0000', $second->reservations()->first()->quantity_consumed);
        $this->postDelivery($this->delivery($second));
        $this->assertSame('2.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('2.0000', $this->balance()->quantity_reserved);
        $this->assertSame('0.0000', $this->balance()->quantity_available);
    }

    public function test_missing_settings_layers_or_location_valuation_roll_back_unchanged(): void
    {
        $delivery = $this->delivery($this->order());
        $setting = InventoryValuationSetting::firstOrFail();
        $setting->delete();
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'fifo']);
        FifoLayer::query()->update(['remaining_qty' => '0']);
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
        FifoLayer::query()->update(['remaining_qty' => '10']);
        InventoryCostState::query()->where('variant_id', $this->variant->id)->delete();
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unallocated_or_inactive_variant_after_draft_is_rejected(): void
    {
        $delivery = $this->delivery($this->order());
        $this->variant->update(['is_active' => false]);
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
        DB::table('product_variants')->where('id', $this->variant->id)->update(['is_active' => true, 'unallocated_product_id' => $this->variant->product_id]);
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_inventory_permission_is_required_and_cross_branch_service_access_is_denied(): void
    {
        $delivery = $this->delivery($this->order());
        $viewer = $this->scoped([SalesPermissions::DELIVERIES_VIEW, SalesPermissions::DELIVERIES_POST]);
        $this->assertFalse(Gate::allows('post', $delivery));
        $this->reject(fn () => $this->posting->post($viewer, $delivery, $this->drafts->revision($delivery)), HttpException::class);
        $poster = $this->scoped([SalesPermissions::DELIVERIES_VIEW, SalesPermissions::DELIVERIES_POST, InventoryPermissions::POST]);
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $poster->locations()->attach($other);
        app(LocationContext::class)->setFor($poster, $other->id);
        $this->reject(fn () => $this->posting->post($poster, $delivery, $this->drafts->revision($delivery)), HttpException::class);
    }

    public function test_direct_fabricated_valuation_commands_cannot_bypass_the_sales_workflow(): void
    {
        $delivery = $this->delivery($this->order());
        $item = $delivery->items->first();
        $before = $this->snapshot();
        $this->reject(fn () => DB::transaction(fn () => app(InventoryPostingService::class)->post(
            new StockPostingCommand($this->variant, $this->branch, StockMovementType::Sale, '-1', 'fabricated',
                reference: $item, valuationUnitCost: '0.000001'), $this->actor)));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_late_valuation_failure_rolls_back_stock_consumption_entries_and_status(): void
    {
        $delivery = $this->delivery($this->order(['4', '6']));
        $before = $this->snapshot();
        $name = 'eloquent.created: '.InventoryCostEntry::class;
        Event::listen($name, function (InventoryCostEntry $entry): void {
            if ($entry->entry_type === 'issue') {
                throw new \RuntimeException('Injected valuation failure after insert');
            }
        });
        try {
            $this->reject(fn () => $this->postDelivery($delivery), \RuntimeException::class);
        } finally {
            Event::forget($name);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_posted_documents_movements_costs_and_consumptions_reject_quiet_writes(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $item = $delivery->items->first();
        foreach ([$delivery, $item, $item->stockMovement, $item->stockMovement->inventoryCostEntry,
            $item->reservationConsumption, $item->stockMovement->inventoryCostEntry->fifoLayerConsumptions->first()] as $record) {
            $this->reject(fn () => $record->fresh()->forceFill(['updated_at' => now()->addDay()])->saveQuietly());
            $this->reject(fn () => $record->fresh()->deleteQuietly());
        }
        $this->reject(fn () => $this->drafts->cancel($this->actor, $delivery, $this->drafts->revision($delivery)));
        $this->assertFalse(Gate::allows('cancel', $delivery));
        $part = $item->stockMovement->inventoryCostEntry->fifoLayerConsumptions->first();
        $this->reject(fn () => FifoLayerConsumption::create(['cost_entry_id' => $part->cost_entry_id, 'fifo_layer_id' => $part->fifo_layer_id,
            'quantity' => '1', 'unit_cost' => '2', 'total_cost' => '2']));
        $this->reject(fn () => StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $this->branch->id,
            'type' => 'sale', 'quantity' => '-1', 'reference_type' => $item->getMorphClass(), 'reference_id' => $item->id]));
    }

    public function test_consumption_uniqueness_and_composite_ownership_are_database_enforced(): void
    {
        $this->assertTrue(Schema::hasIndex('sales_reservation_consumptions', 'idx_sales_consumption_order_history'));
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $event = SalesReservationConsumption::firstOrFail();
        $duplicate = $event->getAttributes();
        unset($duplicate['id']);
        $duplicate['uuid'] = (string) Str::uuid7();
        $this->reject(fn () => DB::table('sales_reservation_consumptions')->insert($duplicate), QueryException::class);
        $this->reject(fn () => DB::table('sales_reservation_consumptions')->where('id', $event->id)->update(['variant_id' => 999999]), QueryException::class);
        $this->reject(fn () => DB::table('sales_reservation_consumptions')->where('id', $event->id)->update(['quantity' => '0']), QueryException::class);
    }

    public function test_arabic_post_action_records_evidence_and_hides_mutations_after_posting(): void
    {
        app()->setLocale('ar');
        $delivery = $this->delivery($this->order());
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->assertActionVisible('post')
            ->callAction('post')->assertHasNoActionErrors();
        $this->assertSame('posted', $delivery->fresh()->status);
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->assertActionHidden('post')->assertActionHidden('cancel')->assertActionHidden('edit')
            ->assertSee('20.0000');
        $this->assertSame('ترحيل التسليم', __('Post Delivery'));
    }

    public function test_list_post_action_calls_the_same_atomic_workflow(): void
    {
        $delivery = $this->delivery($this->order());
        Livewire::test(ListSalesDeliveries::class)
            ->assertTableActionVisible('post', $delivery)->callTableAction('post', $delivery)->assertHasNoTableActionErrors();
        $this->assertSame('posted', $delivery->fresh()->status);
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
        $this->assertSame('-20.0000', $this->issue($delivery)->total_cost);
    }

    public function test_list_aggregates_do_not_change_revision_or_break_draft_cancellation(): void
    {
        $delivery = $this->delivery($this->order());
        $tableRecord = SalesDelivery::withSum('items', 'quantity')->findOrFail($delivery->id);
        $this->assertSame($this->drafts->revision($delivery), $this->drafts->revision($tableRecord));
        Livewire::test(ListSalesDeliveries::class)
            ->callTableAction('cancel', $delivery)->assertHasNoTableActionErrors();
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame('10.0000', $this->balance()->quantity_reserved);
        $this->assertSame(0, SalesReservationConsumption::count());
    }

    public function test_allocated_fifo_layers_keep_receipt_age_and_source_lineage_when_sold(): void
    {
        $this->variant->update(['size_id' => Size::factory()->create()->id]);
        $source = app(UnallocatedVariantService::class)->ensure($this->actor, $this->variant->product);
        $this->travelTo(now()->subDays(3));
        $this->receive('10', '2', $source);
        $sourceLayer = FifoLayer::where('variant_id', $source->id)->firstOrFail();
        $receiptDate = $sourceLayer->received_at->format('Y-m-d H:i:s');
        $this->travelBack();
        $allocations = app(SizeAllocationService::class);
        $allocation = $allocations->create($this->actor, ['product_id' => $this->variant->product_id, 'location_id' => $this->branch->id,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '10']]], (string) Str::uuid7());
        $allocations->post($this->actor, $allocation, $allocations->revision($allocation));
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $layer = $this->issue($delivery)->fifoLayerConsumptions->first()->fifoLayer;
        $this->assertSame($sourceLayer->id, $layer->source_layer_id);
        $this->assertSame($receiptDate, $layer->received_at->format('Y-m-d H:i:s'));
        $this->assertSame($receiptDate, $sourceLayer->fresh()->received_at->format('Y-m-d H:i:s'));
        $this->assertSame('-20.0000', $this->issue($delivery)->total_cost);
    }

    public function test_fractional_quantities_and_six_decimal_costs_preserve_signed_precision(): void
    {
        $this->receive('1.2345', '12.123456');
        $order = $this->order(['1.2345']);
        $delivery = $this->postDelivery($this->delivery($order));
        $this->assertSame('-1.2345', $this->issue($delivery)->quantity);
        $this->assertSame('-14.9664', $this->issue($delivery)->total_cost);
        $this->assertSame('1.2345', $delivery->items->first()->reservationConsumption->quantity);
        $this->assertSame('0.0000', $this->state()->total_value);
    }

    public function test_unreconciled_historical_fifo_receipt_age_blocks_posting_without_repair(): void
    {
        $delivery = $this->delivery($this->order());
        FifoLayer::where('variant_id', $this->variant->id)->update(['received_at' => now()->subDay()]);
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_or_future_only_standard_revision_blocks_all_posting_changes(): void
    {
        $delivery = $this->delivery($this->order());
        $this->method('standard');
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
        $this->standard('2', now()->addDay()->format('Y-m-d H:i:s'));
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ambiguous_effective_standard_revisions_are_rejected_atomically(): void
    {
        $this->method('standard');
        $revision = $this->standard('2');
        $delivery = $this->delivery($this->order());
        $this->standard('3', $revision->effective_at->format('Y-m-d H:i:s'));
        $before = $this->snapshot();
        $this->reject(fn () => $this->postDelivery($delivery));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_module_branch_and_post_permission_revocations_are_enforced_in_backend(): void
    {
        $delivery = $this->delivery($this->order());
        $before = $this->snapshot();
        foreach (['sales', 'inventory', 'inventory_costing'] as $module) {
            Module::where('code', $module)->update(['is_active' => false]);
            $this->reject(fn () => $this->postDelivery($delivery), HttpException::class);
            Module::where('code', $module)->update(['is_active' => true]);
        }
        $this->branch->update(['is_active' => false]);
        $this->reject(fn () => $this->postDelivery($delivery), HttpException::class);
        $this->branch->update(['is_active' => true]);
        $user = $this->scoped([SalesPermissions::DELIVERIES_VIEW, InventoryPermissions::POST]);
        $this->reject(fn () => $this->posting->post($user, $delivery, $this->drafts->revision($delivery)), HttpException::class);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_cogs_is_hidden_without_inventory_cost_permission(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $user = $this->scoped([SalesPermissions::DELIVERIES_VIEW]);
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->assertSuccessful()->assertDontSee('20.0000');
        $user->roles()->first()->givePermissionTo(InventoryPermissions::COST);
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->assertSuccessful()->assertSee('20.0000');
    }

    public function test_arabic_post_failure_keeps_the_draft_and_every_inventory_record(): void
    {
        $delivery = $this->delivery($this->order());
        FifoLayer::where('variant_id', $this->variant->id)->update(['remaining_qty' => '0']);
        $before = $this->snapshot();
        app()->setLocale('ar');
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->callAction('post')
            ->assertNotified(__('Delivery posting failed'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('draft', $delivery->fresh()->status);
    }

    private function workers(array $deliveries): array
    {
        $configuration = config('database.connections.mysql');
        $this->assertSame('mysql', DB::getDriverName());
        $processes = [];
        $start = microtime(true) + 2;
        try {
            foreach ($deliveries as $delivery) {
                $isReceipt = $delivery instanceof PurchaseReceipt;
                $process = new Process([PHP_BINARY, base_path($isReceipt ? 'tests/Support/PurchaseReceiptWorker.php' : 'tests/Support/SalesOrderWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'SALES_ACTOR' => (string) $this->actor->id, 'SALES_START' => (string) $start,
                    'SALES_OPERATION' => 'delivery-post', 'SALES_DELIVERY' => (string) $delivery->id,
                    'SALES_REVISION' => $isReceipt ? '' : $this->drafts->revision($delivery), 'SALES_ALLOW_REJECTION' => '1',
                    'RECEIPT_ACTOR' => (string) $this->actor->id, 'RECEIPT_ID' => (string) $delivery->id,
                    'RECEIPT_REVISION' => $isReceipt ? app(PurchaseReceiptService::class)->revision($delivery) : '', 'RECEIPT_START' => (string) $start,
                ]);
                $process->setTimeout(60)->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_concurrent_same_delivery_posts_once_with_verified_replay(): void
    {
        $delivery = $this->delivery($this->order());
        $results = $this->workers([$delivery, $delivery, $delivery]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame($results[0], $results[2]);
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
        $this->assertDatabaseCount('sales_reservation_consumptions', 1);
        $this->assertSame('0.0000', $this->balance()->quantity_on_hand);
    }

    public function test_concurrent_deliveries_of_one_order_cannot_overdeliver(): void
    {
        $order = $this->order();
        $results = $this->workers([$this->delivery($order, ['7']), $this->delivery($order, ['7'])]);
        $this->assertCount(1, array_filter($results, fn ($row): bool => isset($row['rejected'])));
        $this->assertSame('3.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('3.0000', $this->balance()->quantity_reserved);
        $this->assertSame('0.0000', $this->balance()->quantity_available);
    }

    public function test_concurrent_orders_share_stock_without_consuming_each_others_reservations(): void
    {
        $first = $this->order(['6']);
        $second = $this->order(['4']);
        $results = $this->workers([$this->delivery($first), $this->delivery($second)]);
        $this->assertCount(0, array_filter($results, fn ($row): bool => isset($row['rejected'])));
        $this->assertSame('0.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('0.0000', $this->balance()->quantity_reserved);
        $this->assertSame('0.0000', $this->state()->total_value);
    }
}
