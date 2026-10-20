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
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\ReceiptValuationService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\EditPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\PurchasingPhase3TestSetup;
use Tests\TestCase;

class PurchaseReceiptManagementTest extends TestCase
{
    use PurchasingPhase3TestSetup;

    private User $actor;

    private Location $location;

    private Supplier $supplier;

    private ProductVariant $variant;

    private PurchaseOrderService $orders;

    private PurchaseReceiptService $receipts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3Database();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'inventory', 'inventory_costing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->location = Location::create(['name' => 'Receipt warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active', 'category_id' => Category::factory()->create()->id])->id,
            'sale_price' => '250.0000', 'cost_price' => '99.0000']);
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'fifo']);
        $this->orders = app(PurchaseOrderService::class);
        $this->receipts = app(PurchaseReceiptService::class);
    }

    private function order(string $quantity = '100', string $price = '150', array $extra = [], bool $confirm = true): PurchaseOrder
    {
        $order = $this->orders->create($this->actor, array_replace([
            'supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'order_date' => '2026-10-14',
            'items' => [['variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ], $extra), (string) Str::uuid7());

        return $confirm ? $this->orders->confirm($this->actor, $order, $this->orders->revision($order)) : $order;
    }

    private function draft(PurchaseOrder $order): PurchaseReceipt
    {
        return $order->receipts()->where('status', 'draft')->firstOrFail();
    }

    private function save(PurchaseReceipt $receipt, array $quantities, ?array $costs = null): PurchaseReceipt
    {
        $items = $receipt->items()->orderBy('id')->get()->map(fn ($item, $index): array => [
            'id' => $item->id, 'quantity' => $quantities[$index], 'unit_cost' => $costs[$index] ?? $item->unit_cost,
        ])->all();

        return $this->receipts->update($this->actor, $receipt, ['items' => $items], $this->receipts->revision($receipt));
    }

    private function postReceipt(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return $this->receipts->post($this->actor, $receipt, $this->receipts->revision($receipt));
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);

            return;
        }
        $this->fail('Expected operation rejection.');
    }

    private function method(string $method): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => $method]);
    }

    private function state(): InventoryCostState
    {
        return InventoryCostState::where('variant_id', $this->variant->id)->where('location_id', $this->location->id)->firstOrFail();
    }

    private function standard(string $cost = '120.000000', ?string $effective = null): InventoryStandardCostRevision
    {
        $standard = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => $cost]);

        return InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => $cost,
            'effective_at' => $effective ?? now()->subDay()]);
    }

    private function scoped(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->location);
        app(LocationContext::class)->setFor($user, $this->location->id);
        $role = Role::create(['name' => 'receiver-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_confirmation_prepares_one_receipt_without_stock_or_valuation_and_retry_is_safe(): void
    {
        $order = $this->order();
        $receipt = $this->draft($order);
        $this->assertSame('100.0000', $receipt->items->first()->expected_quantity);
        $this->assertSame('0.0000', $receipt->items->first()->quantity);
        $this->assertSame($order->location_id, $receipt->location_id);
        $this->orders->confirm($this->actor, $order, 'stale-retry');
        $this->assertSame(1, $order->receipts()->count());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_cost_entries', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('vendor_bills', 0);
    }

    public function test_existing_confirmed_order_requires_explicit_idempotent_preparation(): void
    {
        $order = $this->order(confirm: false);
        DB::table('purchase_orders')->where('id', $order->id)->update(['status' => 'confirmed']);
        $order->refresh();
        $this->orders->confirm($this->actor, $order, 'old-retry');
        $this->assertSame(0, $order->receipts()->count());
        $first = $this->receipts->prepare($this->actor, $order);
        $second = $this->receipts->prepare($this->actor, $order);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $order->receipts()->count());
    }

    public function test_fifo_partial_and_full_receiving_preserve_traceability_and_backorders(): void
    {
        $order = $this->order();
        $receipt = $this->save($this->draft($order), ['40']);
        $this->assertDatabaseCount('stock_movements', 0);
        $revision = $this->receipts->revision($receipt);
        $posted = $this->postReceipt($receipt);
        $this->assertSame('partially_received', $order->refresh()->status);
        $this->assertSame('40.0000', $this->state()->on_hand_qty);
        $this->assertSame('6000.0000', $this->state()->total_value);
        $layer = FifoLayer::firstOrFail();
        $this->assertSame('40.0000', $layer->remaining_qty);
        $this->assertSame('150.000000', $layer->unit_cost);
        $this->assertSame($receipt->id, $layer->stockMovement->reference->purchase_receipt_id);
        $this->assertSame($this->supplier->id, $layer->stockMovement->reference->purchaseOrder->supplier_id);
        $this->assertSame($this->location->id, $layer->stockMovement->location_id);
        $backorder = $this->draft($order);
        $this->assertSame('60.0000', $backorder->items->first()->expected_quantity);
        $this->assertSame($posted->id, $backorder->backorder_of_id);
        $this->receipts->post($this->actor, $receipt, $revision);
        $this->assertDatabaseCount('fifo_layers', 1);
        $this->assertSame(2, $order->receipts()->count());
        $this->postReceipt($this->save($backorder, ['60']));
        $this->assertSame('received', $order->refresh()->status);
        $this->assertSame('100.0000', $this->state()->on_hand_qty);
        $this->assertSame('0.0000', $this->receipts->summary($order)['remaining']);
        $this->assertNull($this->receipts->prepare($this->actor, $order));
        $this->assertDatabaseCount('fifo_layers', 2);
    }

    public function test_zero_lines_and_multiple_variants_are_handled_without_zero_movements(): void
    {
        $other = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $order = $this->order(extra: ['items' => [
            ['variant_id' => $this->variant->id, 'quantity' => '10', 'unit_price' => '100'],
            ['variant_id' => $other->id, 'quantity' => '20', 'unit_price' => '150'],
        ]]);
        $receipt = $this->save($this->draft($order), ['0', '5']);
        $posted = $this->postReceipt($receipt);
        $this->assertNull($posted->items->first()->stock_movement_id);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(['10.0000', '15.0000'], $this->draft($order)->items->pluck('expected_quantity')->all());
    }

    public function test_duplicate_variants_on_distinct_po_lines_share_one_location_cost_state(): void
    {
        $this->method('avco');
        $order = $this->order(extra: ['items' => [
            ['variant_id' => $this->variant->id, 'quantity' => '10', 'unit_price' => '100'],
            ['variant_id' => $this->variant->id, 'quantity' => '10', 'unit_price' => '150'],
        ]]);
        $this->postReceipt($this->save($this->draft($order), ['10', '10']));
        $this->assertSame('125.000000', $this->state()->avg_cost);
        $this->assertSame('20.0000', $this->state()->on_hand_qty);
        $this->assertDatabaseCount('inventory_cost_states', 1);
        $this->assertDatabaseCount('inventory_cost_entries', 2);
    }

    public function test_overreceiving_and_all_zero_receipts_are_rejected(): void
    {
        $order = $this->order('10');
        $receipt = $this->draft($order);
        $this->reject(fn () => $this->postReceipt($receipt));
        $receipt = $this->save($receipt, ['11']);
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('draft', $receipt->refresh()->status);
    }

    public function test_multiple_drafts_cannot_post_beyond_remaining_quantity(): void
    {
        $order = $this->order('10');
        $first = $this->save($this->draft($order), ['7']);
        $other = PurchaseReceipt::factory()->create(['purchase_order_id' => $order->id, 'location_id' => $order->location_id]);
        $other->items()->create(['purchase_order_id' => $order->id, 'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => '7', 'unit_cost' => '150', 'expected_quantity' => '10']);
        $this->postReceipt($first);
        $this->assertSame(2, $order->receipts()->count());
        $this->reject(fn () => $this->postReceipt($other));
        $this->assertSame('7.0000', $this->state()->on_hand_qty);
    }

    public function test_draft_cancellation_is_retained_and_explicit_replacement_does_not_close_po(): void
    {
        $order = $this->order();
        $receipt = $this->save($this->draft($order), ['20']);
        $revision = $this->receipts->revision($receipt);
        $this->receipts->cancel($this->actor, $receipt, $revision);
        $this->receipts->cancel($this->actor, $receipt, $revision);
        $this->assertSame('confirmed', $order->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->reject(fn () => $receipt->delete());
        $replacement = $this->receipts->prepare($this->actor, $order);
        $this->assertNotSame($receipt->id, $replacement->id);
        $this->assertSame('100.0000', $replacement->items->first()->expected_quantity);
    }

    public function test_posted_receipt_lines_and_valuation_are_immutable_and_cannot_be_generically_reversed(): void
    {
        $receipt = $this->postReceipt($this->save($this->draft($this->order('10')), ['10']));
        $this->reject(fn () => $this->receipts->cancel($this->actor, $receipt, $this->receipts->revision($receipt)));
        $this->reject(fn () => $this->save($receipt, ['9']));
        $this->reject(fn () => $receipt->update(['notes' => 'changed']));
        $this->reject(fn () => $receipt->items->first()->update(['quantity' => '9']));
        $this->reject(fn () => InventoryCostEntry::first()->update(['total_cost' => '1']));
        $movement = $receipt->items->first()->stockMovement;
        $this->reject(fn () => app(InventoryPostingService::class)->reverse($movement, 'bad-reversal', $this->actor, 'attempt'));
        $this->assertSame('10.0000', $this->state()->on_hand_qty);
    }

    public function test_avco_uses_current_inventory_value_and_is_idempotent(): void
    {
        $this->method('avco');
        $this->postReceipt($this->save($this->draft($this->order('10', '100')), ['10']));
        $receipt = $this->save($this->draft($this->order('10', '150')), ['10']);
        $revision = $this->receipts->revision($receipt);
        $this->postReceipt($receipt);
        $this->assertSame('125.000000', $this->state()->avg_cost);
        $this->assertSame('2500.0000', $this->state()->total_value);
        $this->receipts->post($this->actor, $receipt, $revision);
        $this->assertSame('125.000000', $this->state()->avg_cost);
        $this->assertDatabaseCount('inventory_cost_entries', 2);
        $this->assertDatabaseCount('fifo_layers', 0);
        $this->assertSame(['100.000000', '150.000000'], InventoryCostEntry::orderBy('id')->pluck('purchase_unit_cost')->all());
    }

    public function test_avco_uses_total_value_even_when_cached_average_has_been_rounded(): void
    {
        $this->method('avco');
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'quantity_on_hand' => '3', 'quantity_reserved' => '0', 'quantity_available' => '3']);
        InventoryCostState::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'on_hand_qty' => '3', 'total_value' => '100.0000', 'avg_cost' => '33.333333']);
        $this->postReceipt($this->save($this->draft($this->order('1', '50')), ['1']));
        $this->assertSame('37.500000', $this->state()->avg_cost);
        $this->assertSame('150.0000', $this->state()->total_value);
    }

    public function test_location_cost_states_do_not_mix_suppliers_or_locations(): void
    {
        $this->method('avco');
        $this->postReceipt($this->save($this->draft($this->order('10', '100')), ['10']));
        $branch = Location::create(['name' => 'Branch', 'type' => 'branch', 'is_active' => true]);
        $supplier = Supplier::factory()->create();
        $this->postReceipt($this->save($this->draft($this->order('15', '150', ['location_id' => $branch->id, 'supplier_id' => $supplier->id])), ['15']));
        $this->assertSame('100.000000', $this->state()->avg_cost);
        $this->assertSame('150.000000', InventoryCostState::where('location_id', $branch->id)->firstOrFail()->avg_cost);
        $this->assertDatabaseCount('inventory_cost_states', 2);
        $this->assertSame('250.0000', $this->variant->refresh()->sale_price);
        $this->assertSame('99.0000', $this->variant->cost_price);
    }

    public function test_standard_price_values_at_effective_revision_and_records_signed_variance(): void
    {
        $this->method('standard');
        $revision = $this->standard();
        $receipt = $this->postReceipt($this->save($this->draft($this->order('10', '150')), ['10']));
        $entry = InventoryCostEntry::firstOrFail();
        $this->assertSame('1500.0000', $entry->purchase_value);
        $this->assertSame('1200.0000', $entry->total_cost);
        $this->assertSame('300.0000', $entry->purchase_price_variance);
        $this->assertSame('120.000000', $entry->unit_cost);
        $this->assertSame($revision->id, $entry->standard_cost_revision_id);
        $this->assertSame('150.000000', $receipt->items->first()->unit_cost);
        $this->assertNull($receipt->items->first()->stockMovement->unit_cost);
        $this->assertSame('120.000000', $receipt->items->first()->stockMovement->valuation_unit_cost);
        $this->assertSame('120.000000', $revision->standardCost->refresh()->standard_cost);
        $this->reject(fn () => $revision->update(['new_cost' => '1']));
        $this->postReceipt($receipt);
        $this->assertDatabaseCount('inventory_cost_entries', 1);
        $this->postReceipt($this->save($this->draft($this->order('1', '100')), ['1']));
        $this->assertSame('-20.0000', InventoryCostEntry::orderByDesc('id')->first()->purchase_price_variance);
    }

    public static function standardHardeningReceipts(): array
    {
        return [
            'independent thirds' => ['0.333333', '1', 3, '1.0000'],
            'grouped thirds' => ['0.333333', '3', 1, '1.0000'],
            'half-up and zero delta' => ['0.000050', '1', 3, '0.0002'],
            'fractional pieces' => ['0.333333', '0.0001', 3, '0.0001'],
            'zero standard' => ['0.000000', '1', 3, '0.0000'],
            'near maximum unit precision' => ['999999999999.999900', '1', 1, '999999999999.9999'],
            'maximum quantity precision' => ['0.000001', '99999999999999.9999', 1, '100000000.0000'],
        ];
    }

    #[DataProvider('standardHardeningReceipts')]
    public function test_standard_hardening_receipts_reconcile_targets_variance_and_replay(string $cost, string $quantity, int $count, string $expectedValue): void
    {
        $this->method('standard');
        $revision = $this->standard($cost);
        $received = BigDecimal::zero();
        $previousValue = BigDecimal::zero();
        $historical = [];
        for ($index = 0; $index < $count; $index++) {
            $receipt = $this->save($this->draft($this->order($quantity, $cost)), [$quantity]);
            $postingRevision = $this->receipts->revision($receipt);
            $posted = $this->postReceipt($receipt);
            $received = $received->plus($quantity);
            $target = $received->multipliedBy($cost)->toScale(4, RoundingMode::HalfUp);
            $entry = $posted->items()->firstOrFail()->stockMovement->inventoryCostEntry;
            $this->assertSame((string) $received->toScale(4), $this->state()->on_hand_qty);
            $this->assertSame((string) $target, $this->state()->total_value);
            $this->assertSame((string) $target->minus($previousValue), $entry->total_cost);
            $this->assertSame((string) BigDecimal::of($entry->purchase_value)->minus($entry->total_cost), $entry->purchase_price_variance);
            $this->assertSame($revision->id, $entry->standard_cost_revision_id);
            $this->assertSame($cost, $entry->unit_cost);
            $historical[$entry->id] = $entry->getAttributes();
            $beforeReplay = $this->hardeningSnapshot();
            $this->receipts->post($this->actor, $posted, $postingRevision);
            $this->assertSame($beforeReplay, $this->hardeningSnapshot());
            $previousValue = $target;
        }
        $this->assertSame($expectedValue, $this->state()->total_value);
        $this->assertSame($expectedValue, (string) BigDecimal::of(InventoryCostEntry::sum('total_cost'))->toScale(4));
        foreach ($historical as $id => $attributes) {
            $this->assertSame($attributes, InventoryCostEntry::findOrFail($id)->getAttributes());
        }
        $this->assertDatabaseCount('fifo_layers', 0);
    }

    public function test_standard_hardening_multiple_receipt_lines_share_one_running_target(): void
    {
        $this->method('standard');
        $this->standard('0.333333');
        $order = $this->order(extra: ['items' => array_fill(0, 3, [
            'variant_id' => $this->variant->id, 'quantity' => '1', 'unit_price' => '0.333333',
        ])]);
        $this->postReceipt($this->save($this->draft($order), ['1', '1', '1']));
        $this->assertSame('3.0000', $this->state()->on_hand_qty);
        $this->assertSame('1.0000', $this->state()->total_value);
        $this->assertSame(['0.3333', '0.3334', '0.3333'], InventoryCostEntry::orderBy('id')->pluck('total_cost')->all());
        $this->assertSame(['0.0000', '-0.0001', '0.0000'], InventoryCostEntry::orderBy('id')->pluck('purchase_price_variance')->all());
    }

    public function test_standard_hardening_revision_change_never_implicitly_revalues_opening_stock(): void
    {
        $this->method('standard');
        $original = $this->standard('2');
        $this->postReceipt($this->save($this->draft($this->order('1', '2')), ['1']));
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $original->standard_cost_id,
            'new_cost' => '3', 'effective_at' => now()->subHour()]);
        $receipt = $this->save($this->draft($this->order('1', '3')), ['1']);
        $before = $this->hardeningSnapshot();
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertSame($before, $this->hardeningSnapshot());
    }

    public function test_standard_hardening_historical_rounding_discrepancy_is_not_repaired_by_receiving(): void
    {
        $this->method('standard');
        $this->standard('0.333333');
        $this->postReceipt($this->save($this->draft($this->order('3', '0.333333')), ['3']));
        DB::table('inventory_cost_states')->where('id', $this->state()->id)->update(['total_value' => '0.9999', 'avg_cost' => '0.333300']);
        $receipt = $this->save($this->draft($this->order('1', '0.333333')), ['1']);
        $before = $this->hardeningSnapshot();
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertSame($before, $this->hardeningSnapshot());
    }

    public function test_standard_hardening_late_failure_rolls_back_delta_variance_and_numbering(): void
    {
        $this->method('standard');
        $this->standard('0.333333');
        $this->postReceipt($this->save($this->draft($this->order('1', '0.333333')), ['1']));
        $receipt = $this->save($this->draft($this->order('1', '0.333333')), ['1']);
        $before = $this->hardeningSnapshot();
        $real = app(ReceiptValuationService::class);
        $mock = \Mockery::mock(ReceiptValuationService::class);
        $mock->shouldReceive('prepare')->andReturnUsing(fn ($items, $at) => $real->prepare($items, $at));
        $mock->shouldReceive('post')->andReturnUsing(function ($items, $movements, $plans, $location, $at) use ($real): void {
            $real->post($items, $movements, $plans, $location, $at);
            throw new \RuntimeException('Injected failure after Standard Cost writes.');
        });
        $this->app->instance(ReceiptValuationService::class, $mock);
        $this->receipts = app(PurchaseReceiptService::class);
        $this->reject(fn () => $this->postReceipt($receipt), \RuntimeException::class);
        $this->assertSame($before, $this->hardeningSnapshot());
    }

    public function test_standard_hardening_concurrent_receipts_preserve_one_shared_target(): void
    {
        $this->method('standard');
        $this->standard('0.333333');
        $receipts = [];
        for ($index = 0; $index < 3; $index++) {
            $receipts[] = $this->save($this->draft($this->order('1', '0.333333')), ['1']);
        }
        $results = $this->workers($receipts);
        $this->assertSame(['posted', 'posted', 'posted'], array_column($results, 'status'));
        $this->assertSame('3.0000', $this->state()->on_hand_qty);
        $this->assertSame('1.0000', $this->state()->total_value);
        $this->assertSame(['0.3333', '0.3334', '0.3333'], InventoryCostEntry::orderBy('id')->pluck('total_cost')->all());
        $this->assertDatabaseCount('stock_movements', 3);
    }

    private function hardeningSnapshot(): array
    {
        return collect(['purchase_orders', 'purchase_receipts', 'purchase_receipt_items', 'stock_balances', 'stock_movements',
            'inventory_cost_states', 'inventory_cost_entries', 'document_numbers', 'document_number_sequences'])
            ->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_standard_hardening_unrepresentable_average_is_rejected_atomically(): void
    {
        $this->method('standard');
        $this->standard('999999999999.999999');
        $receipt = $this->save($this->draft($this->order('0.0001', '999999999999.999999')), ['0.0001']);
        $before = $this->hardeningSnapshot();
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertSame($before, $this->hardeningSnapshot());
    }

    public function test_standard_revisions_use_effective_dates_and_deterministic_equal_cost_ties(): void
    {
        $this->method('standard');
        $initial = $this->standard('100.000000');
        $later = InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $initial->standard_cost_id,
            'old_cost' => '100', 'new_cost' => '120', 'effective_at' => now()->subHour()]);
        $tie = InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $initial->standard_cost_id,
            'new_cost' => '120', 'effective_at' => $later->effective_at]);
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $initial->standard_cost_id,
            'new_cost' => '999', 'effective_at' => now()->addDay()]);
        $this->postReceipt($this->save($this->draft($this->order('1')), ['1']));
        $this->assertSame($tie->id, InventoryCostEntry::firstOrFail()->standard_cost_revision_id);
        $this->assertSame('120.000000', $this->state()->avg_cost);
    }

    public function test_missing_future_only_or_ambiguous_standard_cost_blocks_posting(): void
    {
        $this->method('standard');
        $receipt = $this->save($this->draft($this->order('1')), ['1']);
        $this->reject(fn () => $this->postReceipt($receipt));
        $future = $this->standard('120.000000', now()->addDay()->format('Y-m-d H:i:s'));
        $this->reject(fn () => $this->postReceipt($receipt));
        $future->update(['effective_at' => now()->subHour()]);
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $future->standard_cost_id,
            'new_cost' => '121', 'effective_at' => $future->effective_at]);
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_cost_entries', 0);
    }

    public function test_six_decimal_costs_and_four_decimal_quantity_and_value_are_preserved(): void
    {
        $receipt = $this->postReceipt($this->save($this->draft($this->order('1.2345', '100.123456')), ['1.2345']));
        $entry = InventoryCostEntry::firstOrFail();
        $this->assertSame('100.123456', $entry->purchase_unit_cost);
        $this->assertSame('123.6024', $entry->total_cost);
        $this->assertSame('100.123456', FifoLayer::firstOrFail()->unit_cost);
        $this->assertSame('100.123456', $receipt->items->first()->stockMovement->valuation_unit_cost);
        $this->assertSame('1.2345', StockBalance::firstOrFail()->quantity_on_hand);
    }

    public function test_legacy_aggregate_or_unvalued_opening_stock_is_not_guessed(): void
    {
        $legacy = InventoryCostState::factory()->create(['variant_id' => $this->variant->id,
            'on_hand_qty' => '10', 'total_value' => '1000', 'avg_cost' => '100']);
        $receipt = $this->save($this->draft($this->order('1')), ['1']);
        $snapshot = $legacy->refresh()->getAttributes();
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertSame($snapshot, $legacy->refresh()->getAttributes());
        $this->assertNull($legacy->location_id);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unvalued_opening_stock_rolls_back_provisional_stock_posting(): void
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'quantity_on_hand' => '10', 'quantity_reserved' => '0', 'quantity_available' => '10']);
        $receipt = $this->save($this->draft($this->order('1')), ['1']);
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertSame('10.0000', StockBalance::firstOrFail()->quantity_on_hand);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_cost_states', 0);
        $this->assertSame('draft', $receipt->refresh()->status);
    }

    public function test_late_valuation_failure_rolls_back_stock_fifo_entries_and_po_together(): void
    {
        $receipt = $this->save($this->draft($this->order('1')), ['1']);
        $real = app(ReceiptValuationService::class);
        $mock = \Mockery::mock(ReceiptValuationService::class);
        $mock->shouldReceive('prepare')->andReturnUsing(fn ($items, $at) => $real->prepare($items, $at));
        $mock->shouldReceive('post')->andReturnUsing(function ($items, $movements, $plans, $location, $at) use ($real): void {
            $real->post($items, $movements, $plans, $location, $at);
            throw new \RuntimeException('Injected failure after valuation writes.');
        });
        $this->app->instance(ReceiptValuationService::class, $mock);
        $this->receipts = app(PurchaseReceiptService::class);
        $this->reject(fn () => $this->postReceipt($receipt), \RuntimeException::class);
        foreach (['stock_movements', 'stock_balances', 'inventory_cost_entries', 'fifo_layers', 'inventory_cost_states'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('draft', $receipt->refresh()->status);
        $this->assertSame('confirmed', $receipt->purchaseOrder->status);
        $this->assertNull($receipt->items->first()->stock_movement_id);
    }

    public function test_permissions_location_scope_module_activation_and_inventory_permission_are_enforced(): void
    {
        $receipt = $this->save($this->draft($this->order('1')), ['1']);
        $user = $this->scoped([PurchasingPermissions::RECEIPTS_VIEW]);
        $this->assertTrue(Gate::forUser($user)->allows('view', $receipt));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->reject(fn () => $this->receipts->post($user, $receipt, $this->receipts->revision($receipt)), HttpException::class);
        $user->givePermissionTo(PurchasingPermissions::RECEIPTS_POST);
        $this->reject(fn () => $this->receipts->post($user, $receipt, $this->receipts->revision($receipt)), HttpException::class);
        $user->givePermissionTo('inventory.post');
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($user)->allows('view', $receipt));
        $this->reject(fn () => $this->receipts->post($user, $receipt, $this->receipts->revision($receipt)), HttpException::class);
        Module::where('code', 'purchasing')->update(['is_active' => true]);
        $other = Location::create(['name' => 'Other location', 'type' => 'branch', 'is_active' => true]);
        $user->locations()->attach($other);
        app(LocationContext::class)->setFor($user, $other->id);
        $this->assertFalse(Gate::forUser($user)->allows('view', $receipt));
        $this->assertSame(0, app(PurchasingAccess::class)->scopeReceipts(PurchaseReceipt::query(), $user)->count());
        $this->reject(fn () => $this->receipts->post($user, $receipt, $this->receipts->revision($receipt)), HttpException::class);
        $this->assertDatabaseCount('stock_movements', 0);
        app(LocationContext::class)->setFor($user, $this->location->id);
        $posted = $this->receipts->post($user, $receipt, $this->receipts->revision($receipt));
        $this->assertSame('posted', $posted->status);
        $this->assertSame('1.0000', $this->state()->on_hand_qty);
    }

    public function test_missing_category_costing_or_disabled_costing_never_falls_back(): void
    {
        $receipt = $this->save($this->draft($this->order('1')), ['1']);
        InventoryValuationSetting::query()->delete();
        $this->reject(fn () => $this->postReceipt($receipt));
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'fifo']);
        Module::where('code', 'inventory_costing')->update(['is_active' => false]);
        $this->reject(fn () => $this->postReceipt($receipt), HttpException::class);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_destination_tampering_line_identity_and_stale_drafts_are_rejected(): void
    {
        $receipt = $this->draft($this->order('10'));
        $revision = $this->receipts->revision($receipt);
        $this->reject(fn () => $this->receipts->update($this->actor, $receipt, ['location_id' => 999], $revision));
        $this->reject(fn () => $this->receipts->update($this->actor, $receipt,
            ['items' => [['id' => 99999, 'quantity' => '1', 'unit_cost' => '150']]], $revision));
        $saved = $this->save($receipt, ['1']);
        $this->reject(fn () => $this->receipts->post($this->actor, $saved, $revision));
        DB::table('purchase_receipts')->where('id', $receipt->id)->update(['location_id' => Location::create(['name' => 'Tampered', 'type' => 'branch', 'is_active' => true])->id]);
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_legacy_stock_posting_hashes_replay_unchanged(): void
    {
        $command = new StockPostingCommand($this->variant, $this->location, StockMovementType::Purchase, '1', 'legacy-hash', unitCost: '100');
        $movement = app(InventoryPostingService::class)->post($command, $this->actor);
        $payload = ['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'type' => 'purchase', 'quantity' => '1.0000', 'unit_cost' => '100.0000',
            'reference_type' => null, 'reference_id' => null, 'idempotency_key' => hash('sha256', 'legacy-hash'),
            'created_by' => $this->actor->id, 'system_actor' => null, 'notes' => null, 'reverses_movement_id' => null];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), $movement->posting_payload_hash);
        $this->assertSame($movement->id, app(InventoryPostingService::class)->post($command, $this->actor)->id);
        $this->assertNull($movement->valuation_unit_cost);
    }

    public function test_generic_unvalued_movements_cannot_change_location_valued_stock(): void
    {
        $this->postReceipt($this->save($this->draft($this->order('1')), ['1']));
        $this->reject(fn () => app(InventoryPostingService::class)->post(new StockPostingCommand(
            $this->variant, $this->location, StockMovementType::Sale, '-1', 'unvalued-sale'), $this->actor));
        $this->assertSame('1.0000', StockBalance::firstOrFail()->quantity_on_hand);
        $this->assertSame('1.0000', $this->state()->on_hand_qty);
    }

    public function test_filament_receipt_pages_edit_and_actions_use_services(): void
    {
        $order = $this->order('10');
        $receipt = $this->draft($order);
        Livewire::test(ListPurchaseReceipts::class)->assertSuccessful()->assertCanSeeTableRecords([$receipt]);
        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->assertSuccessful()->assertActionVisible('post');
        Livewire::test(EditPurchaseReceipt::class, ['record' => $receipt->uuid])
            ->fillForm(['items' => [['id' => $receipt->items->first()->id, 'quantity' => '4', 'unit_cost' => '150']]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('4.0000', $receipt->items()->first()->quantity);
        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->callAction('post')->assertHasNoActionErrors();
        $this->assertSame('posted', $receipt->refresh()->status);
        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->assertActionHidden('post')->assertActionHidden('cancel');
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->assertSuccessful()->assertActionVisible('receipts');
    }

    public function test_direct_receipt_routes_and_queries_are_denied_without_view_permission(): void
    {
        $receipt = $this->draft($this->order());
        $user = $this->scoped([]);
        $this->assertSame(0, PurchaseReceiptResource::getEloquentQuery()->count());
        $this->assertFalse(Gate::forUser($user)->allows('view', $receipt));
        $this->get(PurchaseReceiptResource::getUrl('view', ['record' => $receipt]))->assertForbidden();
    }

    public function test_audit_is_read_only_and_reports_legacy_blockers(): void
    {
        InventoryCostState::factory()->create(['variant_id' => $this->variant->id, 'on_hand_qty' => '7', 'total_value' => '70']);
        $before = InventoryCostState::firstOrFail()->getAttributes();
        $this->artisan('purchasing:audit-valuation')->assertExitCode(1);
        $this->assertSame($before, InventoryCostState::firstOrFail()->getAttributes());
    }

    public function test_location_migration_preserves_every_legacy_cost_value_and_reference(): void
    {
        $this->assertDatabaseCount('inventory_cost_states', 0);
        Schema::drop('inventory_cost_states');
        (require app_path('Modules/InventoryCosting/Database/Migrations/2026_10_09_000025_create_inventory_cost_states_table.php'))->up();
        Schema::table('inventory_cost_states', function ($table): void {
            $table->uuid('uuid')->unique();
        });
        DB::table('inventory_cost_states')->insert(['variant_id' => $this->variant->id, 'on_hand_qty' => '7.1234',
            'avg_cost' => '100.123456', 'total_value' => '713.2194', 'uuid' => (string) Str::uuid7(),
            'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('inventory_cost_states')->first();
        (require app_path('Modules/InventoryCosting/Database/Migrations/2026_10_14_074016_scope_inventory_cost_states_by_location.php'))->up();
        $after = (array) DB::table('inventory_cost_states')->first();
        foreach ($before as $key => $value) {
            $this->assertSame($value, $after[$key]);
        }
        $this->assertNull($after['location_id']);
        $this->assertSame($this->variant->id, (int) $after['legacy_variant_id']);
        $this->assertDatabaseCount('inventory_cost_states', 1);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_location_unique_constraints_preserve_one_legacy_row_and_one_state_per_location(): void
    {
        $values = ['variant_id' => $this->variant->id, 'avg_cost' => '100', 'on_hand_qty' => '1', 'total_value' => '100'];
        InventoryCostState::create($values);
        $this->reject(fn () => InventoryCostState::create($values), UniqueConstraintViolationException::class);
        InventoryCostState::create($values + ['location_id' => $this->location->id]);
        $this->reject(fn () => InventoryCostState::create($values + ['location_id' => $this->location->id]), UniqueConstraintViolationException::class);
        $branch = Location::create(['name' => 'Independent state', 'type' => 'branch', 'is_active' => true]);
        InventoryCostState::create($values + ['location_id' => $branch->id]);
        $this->assertDatabaseCount('inventory_cost_states', 3);
    }

    public function test_fifo_opening_layers_must_agree_with_physical_stock_and_value(): void
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'quantity_on_hand' => '10', 'quantity_reserved' => '0', 'quantity_available' => '10']);
        InventoryCostState::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'on_hand_qty' => '10', 'total_value' => '1000', 'avg_cost' => '100']);
        $receipt = $this->save($this->draft($this->order('1')), ['1']);
        $this->reject(fn () => $this->postReceipt($receipt));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('10.0000', $this->state()->on_hand_qty);
        $this->assertSame('10.0000', StockBalance::firstOrFail()->quantity_on_hand);
    }

    public function test_receipt_decimal_inputs_reject_excess_precision_and_value_overflow_atomically(): void
    {
        $receipt = $this->draft($this->order('100000', '1'));
        $this->reject(fn () => $this->save($receipt, ['1.00001']));
        $this->reject(fn () => $this->save($receipt, ['1'], ['1.1234567']));
        $saved = $this->save($receipt, ['100000'], ['999999999999.999999']);
        $this->reject(fn () => $this->postReceipt($saved));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('inventory_cost_entries', 0);
    }

    public function test_receipt_translations_include_permissions_and_actions_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $translations = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach (['Goods Receipts', 'Validate Receipt', 'Save Draft', 'Cancel Draft Receipt', ...array_slice(PurchasingPermissions::all(), -5)] as $key) {
                $this->assertArrayHasKey($key, $translations);
                $this->assertNotSame('', $translations[$key]);
                if ($locale === 'ar') {
                    $this->assertNotSame($key, $translations[$key]);
                }
            }
        }
    }

    /** @return list<array{status: string, id?: int}> */
    private function workers(array $receipts): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the isolated MariaDB runner; SQLite does not prove row locking.');
        }
        $configuration = config('database.connections.mysql');
        $database = DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $database);
        $start = microtime(true) + 2;
        $processes = [];
        try {
            foreach ($receipts as $receipt) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/PurchaseReceiptWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'RECEIPT_ACTOR' => (string) $this->actor->id, 'RECEIPT_ID' => (string) $receipt->id,
                    'RECEIPT_REVISION' => $this->receipts->revision($receipt), 'RECEIPT_START' => (string) $start,
                ]);
                $process->setTimeout(40)->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Isolated receipt worker failed: '.$process->getErrorOutput());
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

    public function test_concurrent_duplicate_validation_posts_stock_and_fifo_once(): void
    {
        $receipt = $this->save($this->draft($this->order('10')), ['10']);
        $results = $this->workers([$receipt, $receipt]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('inventory_cost_entries', 1);
        $this->assertDatabaseCount('fifo_layers', 1);
        $this->assertSame('10.0000', $this->state()->on_hand_qty);
    }

    public function test_concurrent_drafts_cannot_overreceive_the_same_order(): void
    {
        $order = $this->order('10');
        $first = $this->save($this->draft($order), ['7']);
        $other = PurchaseReceipt::factory()->create(['purchase_order_id' => $order->id, 'location_id' => $order->location_id]);
        $other->items()->create(['purchase_order_id' => $order->id, 'purchase_order_item_id' => $order->items->first()->id,
            'quantity' => '7', 'unit_cost' => '150', 'expected_quantity' => '10']);
        $results = $this->workers([$first, $other]);
        $this->assertEqualsCanonicalizing(['posted', 'rejected'], array_column($results, 'status'));
        $this->assertSame('7.0000', $this->state()->on_hand_qty);
        $this->assertSame('partially_received', $order->refresh()->status);
    }

    public function test_concurrent_avco_receipts_from_different_orders_keep_weighted_average(): void
    {
        $this->method('avco');
        $first = $this->save($this->draft($this->order('10', '100')), ['10']);
        $second = $this->save($this->draft($this->order('10', '150')), ['10']);
        $results = $this->workers([$first, $second]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'status'));
        $this->assertSame('20.0000', $this->state()->on_hand_qty);
        $this->assertSame('2500.0000', $this->state()->total_value);
        $this->assertSame('125.000000', $this->state()->avg_cost);
        $this->assertDatabaseCount('inventory_cost_entries', 2);
    }
}
