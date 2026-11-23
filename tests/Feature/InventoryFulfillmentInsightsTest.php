<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Pages\InventoryOverview;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryAdjustmentDrafts;
use App\Modules\Inventory\Services\InventoryInsights;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class InventoryFulfillmentInsightsTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->freezeTime();
        Module::whereIn('code', ['sales', 'products', 'inventory_costing', 'purchasing'])->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->variant->product->update(['category_id' => Category::factory()->create()->id, 'status' => 'active', 'is_active' => true]);
    }

    private function receive(ProductVariant $variant, string $quantity, string $cost): void
    {
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->location->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $variant->product_id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($variant)['receiving_mode'],
                'variant_id' => $variant->id, 'quantity' => $quantity, 'unit_price' => $cost]]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
            'quantity' => $quantity, 'unit_cost' => $cost]]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
    }

    private function deliver(ProductVariant $variant, string $ordered, string $fulfilled): SalesDelivery
    {
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->location->id, 'items' => [[
            'product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => $ordered,
            'unit_price' => '20', 'discount_amount' => '0', 'tax_amount' => '0',
        ]]], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
        $drafts = app(SalesDeliveryService::class);
        $delivery = $drafts->create($this->actor, ['sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => $fulfilled]]], (string) Str::uuid7());

        return app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $drafts->revision($delivery));
    }

    #[TestWith(['fifo'])]
    #[TestWith(['avco'])]
    #[TestWith(['standard'])]
    public function test_ranks_posted_partial_fulfillment_net_of_returns_without_duplicating_cost_slices(string $method): void
    {
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => $method]);
        $second = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        if ($method === 'standard') {
            foreach ([$this->variant, $second] as $variant) {
                $standard = InventoryStandardCost::create(['variant_id' => $variant->id, 'standard_cost' => '2']);
                InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => '2', 'effective_at' => now()->subDay()]);
            }
        }
        $this->receive($this->variant, '10', '2');
        $this->receive($this->variant, '10', '3');
        $this->receive($second, '10', '2');
        $delivery = $this->deliver($this->variant, '20', '12');
        $this->deliver($second, '10', '8');
        $service = app(InventoryInsights::class);
        $this->assertSame([$this->variant->id, $second->id], $service->status($service->query($this->actor, ['location' => $this->location->id]), 'best')->orderByDesc('net_sold')->pluck('id')->all());
        $this->assertSame(12.0, (float) $service->query($this->actor, ['location' => $this->location->id])->where('id', $this->variant->id)->value('net_sold'));

        $returns = app(SalesReturnService::class);
        $return = $returns->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => today()->toDateString(),
            'reason' => 'Customer return', 'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '11', 'disposition' => 'restock']]], (string) Str::uuid7());
        $this->assertSame(12.0, (float) $service->query($this->actor, ['location' => $this->location->id])->where('id', $this->variant->id)->value('net_sold'));
        $manager = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $manager->locations()->attach($this->location);
        $manager->assignRole(Role::whereNull('location_id')->where('name', 'super_admin')->firstOrFail());
        app(LocationContext::class)->setFor($manager, null);
        $return = app(SalesReturnApprovalService::class)->approve($manager, $return, $returns->revision($return));
        $this->assertSame(12.0, (float) $service->query($this->actor, ['location' => $this->location->id])->where('id', $this->variant->id)->value('net_sold'));
        app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));

        $this->assertSame([$second->id, $this->variant->id], $service->status($service->query($this->actor, ['location' => $this->location->id]), 'best')->orderByDesc('net_sold')->pluck('id')->all());
        $row = $service->query($this->actor, ['location' => $this->location->id])->where('id', $this->variant->id)->firstOrFail();
        $this->assertSame(1.0, (float) $row->net_sold);
        $this->assertSame('8.0000', $row->quantity_reserved);
        $this->assertSame('11.0000', $row->quantity_available);
        $this->assertSame(0, $service->status($service->query($this->actor, ['location' => $this->location->id]), 'dead')->count());
        $this->assertSame(0, $service->status($service->query($this->actor, ['location' => $this->location->id, 'from' => today()->addDay()->toDateString(), 'until' => today()->addDay()->toDateString()]), 'best')->count());

        $costBefore = InventoryCostState::where('variant_id', $this->variant->id)->where('location_id', $this->location->id)->firstOrFail()->getAttributes();
        $movementCount = DB::table('stock_movements')->count();
        app(InventoryAdjustmentDrafts::class)->save($this->actor, ['uuid' => (string) Str::uuid7(),
            'variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'observed_on_hand' => '19', 'observed_reserved' => '8',
            'physical_quantity' => '18', 'reason' => 'Physical count for review']);
        $this->assertSame($costBefore, InventoryCostState::where('variant_id', $this->variant->id)->where('location_id', $this->location->id)->firstOrFail()->getAttributes());
        $this->assertDatabaseCount('stock_movements', $movementCount);
        $this->assertSame('19.0000', StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->location->id)->value('quantity_on_hand'));
    }

    public function test_overview_ranks_products_by_net_fulfillment_including_zero_sales_and_keeps_current_stock(): void
    {
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'fifo']);
        $second = ProductVariant::factory()->create();
        $neverSold = ProductVariant::factory()->create();
        foreach ([$this->variant, $second, $neverSold] as $variant) {
            $variant->product->update(['category_id' => $this->variant->product->category_id, 'status' => 'active', 'is_active' => true]);
            $this->receive($variant, '10', '2');
        }
        $this->deliver($this->variant, '6', '6');
        $this->deliver($second, '2', '2');
        $page = Livewire::test(InventoryOverview::class)->set('draftLocation', (string) $this->location->id)
            ->set('draftPerformance', 'best')->call('applyInventoryFilters')->assertHasNoErrors();
        $this->assertSame([$this->variant->product_id, $second->product_id, $neverSold->product_id], $page->instance()->inventory()['rows']->pluck('product_id')->all());
        $page->set('draftPerformance', 'least')->call('applyInventoryFilters');
        $this->assertSame([$neverSold->product_id, $second->product_id, $this->variant->product_id], $page->instance()->inventory()['rows']->pluck('product_id')->all());
        $page->set('draftFrom', today()->addDay()->toDateString())->set('draftUntil', today()->addDay()->toDateString())->call('applyInventoryFilters');
        $data = $page->instance()->inventory();
        $this->assertSame([0.0, 0.0, 0.0], $data['rows']->pluck('net_sold')->map(fn ($quantity): float => (float) $quantity)->all());
        $this->assertSame(22.0, (float) $data['summary']['available']);
        $page->set('draftAvailability', 'low')->set('draftPerformance', '')->call('applyInventoryFilters');
        $this->assertSame([$this->variant->product_id, $second->product_id, $neverSold->product_id], $page->instance()->inventory()['rows']->pluck('product_id')->all());
    }

    public function test_slow_moving_distinguishes_old_sales_from_never_sold_stock_and_excludes_recent_sales(): void
    {
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'fifo']);
        $present = now()->toImmutable();
        $this->travelTo($present->subDays(150));
        $this->receive($this->variant, '10', '2');
        $this->travelTo($present->subDays(100));
        $this->deliver($this->variant, '2', '2');
        $this->travelTo($present);
        $service = app(InventoryInsights::class);
        $query = $service->query($this->actor, ['location' => $this->location->id]);
        $row = $service->intelligence(clone $query, '', 'slow', 90)->firstOrFail();
        $this->assertNotNull($row->last_sale_at);
        $this->assertSame($this->variant->id, $row->id);
        $this->assertSame(0, $service->intelligence(clone $query, '', 'slow', 180)->count());
        $this->deliver($this->variant, '1', '1');
        $this->assertSame(0, $service->intelligence($service->query($this->actor), '', 'slow', 90)->count());
    }
}
