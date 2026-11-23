<?php

namespace Tests\Feature;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Pages\InventoryOverview;
use App\Modules\Inventory\Filament\Pages\ProductInventory;
use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\QuarantineReceiptResource;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\CreateSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\EditSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\ViewSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Filament\Resources\StockBalances\StockBalanceResource;
use App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Inventory\Livewire\AllocationStockTable;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\AllocationStockStatus;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\UnallocatedVariantService;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class AllocationStockStatusTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    private ProductVariant $source;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->freezeTime();
        Module::whereIn('code', ['products', 'purchasing', 'inventory_costing'])->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $product = $this->variant->product;
        $product->update(['name' => 'Supervisor trainer', 'category_id' => Category::factory()->create()->id, 'status' => 'active', 'is_active' => true]);
        $this->variant->update(['size_id' => Size::factory()->create(['name' => '40'])->id, 'color_id' => Color::factory()->create(['name' => 'Blue'])->id]);
        $this->source = app(UnallocatedVariantService::class)->ensure($this->actor, $product);
        $this->supplier = Supplier::factory()->create();
        InventoryValuationSetting::create(['category_id' => $product->category_id, 'costing_method' => 'fifo']);
    }

    private function receive(string $quantity, ?Location $location = null, bool $post = true): PurchaseReceipt
    {
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->create($this->actor, ['supplier_id' => $this->supplier->id, 'location_id' => ($location ?? $this->location)->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $this->source->product_id, 'receiving_mode' => 'unknown',
                'variant_id' => $this->source->id, 'quantity' => $quantity, 'unit_price' => '2']]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id, 'quantity' => $quantity, 'unit_cost' => '2']]], $receipts->revision($receipt));

        return $post ? $receipts->post($this->actor, $receipt, $receipts->revision($receipt)) : $receipt;
    }

    private function draft(string $quantity): SizeAllocation
    {
        return app(SizeAllocationService::class)->create($this->actor, ['product_id' => $this->source->product_id, 'location_id' => $this->location->id,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => $quantity]]], (string) Str::uuid7());
    }

    private function allocate(string $quantity): SizeAllocation
    {
        $service = app(SizeAllocationService::class);
        $draft = $this->draft($quantity);

        return $service->post($this->actor, $draft, $service->revision($draft));
    }

    private function pool(): ProductVariant
    {
        return app(AllocationStockStatus::class)->query($this->actor)->where('id', $this->source->id)->where('allocation_location_id', $this->location->id)->firstOrFail();
    }

    public function test_unallocated_partial_and_full_states_follow_real_posted_allocations(): void
    {
        $this->receive('100');
        Livewire::test(ListStockBalances::class)->assertSee(__('Allocate Now'));
        Livewire::test(InventoryOverview::class)->assertSee(__('Allocate Now'));
        $this->assertSame('unallocated', $this->pool()->allocation_state);
        $this->assertSame(0.0, (float) $this->pool()->allocated_quantity);
        $this->allocate('70');
        $partial = $this->pool();
        $this->assertSame('partial', $partial->allocation_state);
        $this->assertSame(70.0, (float) $partial->allocated_quantity);
        $this->assertSame('30.0000', $partial->remaining_quantity);
        $this->allocate('30');
        $full = $this->pool();
        $this->assertSame('full', $full->allocation_state);
        $this->assertSame(100.0, (float) $full->allocated_quantity);
        Livewire::test(AllocationStockTable::class)->assertCountTableRecords(0)
            ->call('chooseStatus', 'full')->assertSet('tableDeferredFilters.allocation_state.value', 'full')
            ->assertSet('tableFilters.allocation_state.value', 'needs')->assertCountTableRecords(0)
            ->call('applyTableFilters')->assertCountTableRecords(1)->assertTableActionHidden('allocateSizes', $full);
    }

    public function test_all_allocation_screens_reuse_the_existing_form_and_document_permissions(): void
    {
        $this->receive('10');
        $draft = $this->draft('2');
        Livewire::withQueryParams(['product' => $this->source->product_id, 'location' => $this->location->id])
            ->test(CreateSizeAllocation::class)->assertSuccessful()
            ->assertSet('data.product_id', $this->source->product_id)->assertSet('data.location_id', $this->location->id)
            ->assertSee(__('Available unallocated stock'))->assertSee($this->source->sku);
        Livewire::test(EditSizeAllocation::class, ['record' => $draft->getRouteKey()])->assertSuccessful()->assertSee($this->source->product->name);
        Livewire::test(ViewSizeAllocation::class, ['record' => $draft->getRouteKey()])->assertSuccessful()->assertSee($draft->reference);
        $this->assertDatabaseHas('inventory_size_allocations', ['id' => $draft->id, 'status' => 'draft']);
    }

    public function test_all_routed_inventory_screens_render_in_both_directions_without_mutating_records(): void
    {
        $this->receive('10');
        $this->allocate('4');
        $draft = $this->draft('2');
        $before = DB::table('stock_balances')->orderBy('id')->get()->toJson();
        $urls = [
            'overview' => InventoryOverview::getUrl(),
            'product' => ProductInventory::getUrl(['productUuid' => $this->source->product->uuid, 'location' => $this->location->id]),
            'balances' => StockBalanceResource::getUrl(),
            'movements' => StockMovementResource::getUrl(),
            'quarantine' => QuarantineReceiptResource::getUrl(),
            'allocations' => SizeAllocationResource::getUrl(),
            'allocation-history' => SizeAllocationResource::getUrl('index', ['workspace' => 'history']),
            'allocation-create' => SizeAllocationResource::getUrl('create', ['product' => $this->source->product_id, 'location' => $this->location->id]),
            'allocation-edit' => SizeAllocationResource::getUrl('edit', ['record' => $draft]),
            'allocation-view' => SizeAllocationResource::getUrl('view', ['record' => $draft]),
        ];
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            foreach ($urls as $name => $url) {
                Livewire::flushState();
                app()->forgetInstance(NavigationManager::class);
                $response = $this->withSession(['locale' => $locale])->get($url);
                $response->assertOk()->assertSee('dir="'.$direction.'"', false)->assertSee('fox-inventory-page', false);
                if (getenv('INVENTORY_CAPTURE_PREVIEWS') === '1') {
                    $directory = storage_path('app/private/inventory-previews');
                    if (! is_dir($directory)) {
                        mkdir($directory, 0700, true);
                    }
                    file_put_contents($directory.'/unified-'.$name.'-'.$locale.'.html', $response->getContent());
                }
            }
        }
        $this->assertSame($before, DB::table('stock_balances')->orderBy('id')->get()->toJson());
    }

    public function test_multiple_receipts_share_a_pool_without_attributing_allocations_to_one_receipt(): void
    {
        $first = $this->receive('100');
        $this->receive('20');
        $this->allocate('110');
        $pool = $this->pool();
        $this->assertSame('partial', $pool->allocation_state);
        $this->assertSame(120.0, (float) $pool->received_quantity);
        $this->assertSame(110.0, (float) $pool->allocated_quantity);
        $this->assertSame(2, (int) $pool->receipt_count);
        $page = Livewire::test(AllocationStockTable::class)->filterTable('receipt', $first->id)->filterTable('supplier', $this->supplier->id)
            ->assertCountTableRecords(1)->assertTableActionVisible('allocateSizes', $pool);
        $this->assertSame(1, $page->instance()->counts()['partial']);
        $this->assertSame(120.0, (float) $page->instance()->getFilteredTableQuery()->first()->received_quantity);
    }

    public function test_pool_details_modal_shows_the_posted_allocation_matrix_and_exact_receipt_context(): void
    {
        $receipt = $this->receive('10');
        $this->allocate('4');
        $pool = $this->pool();

        Livewire::test(AllocationStockTable::class)->mountTableAction('poolDetails', $pool)
            ->assertMountedActionModalSee($this->source->product->name)
            ->assertMountedActionModalSee($this->source->sku)
            ->assertMountedActionModalSee($this->location->name)
            ->assertMountedActionModalSee($this->supplier->name)
            ->assertMountedActionModalSee($receipt->receipt_number)
            ->assertMountedActionModalSee(__('Color × Size'))
            ->assertMountedActionModalSee('Blue')
            ->assertMountedActionModalSee('40')
            ->assertMountedActionModalSee(QuantityFormatter::format('4.0000'))
            ->assertMountedActionModalSee(QuantityFormatter::format('6.0000'));
    }

    public function test_zero_balance_and_missing_history_remain_visible_for_review(): void
    {
        StockBalance::create(['variant_id' => $this->source->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '0', 'quantity_available' => '0']);
        $this->assertSame('review', $this->pool()->allocation_state);
        Livewire::test(AllocationStockTable::class)->assertCountTableRecords(1)->assertSee(__('Needs review'))
            ->assertTableActionHidden('allocateSizes', $this->pool());
    }

    public function test_draft_cancelled_and_incomplete_posted_history_do_not_complete_a_pool(): void
    {
        $this->receive('10');
        $draft = $this->draft('10');
        $this->assertSame('unallocated', $this->pool()->allocation_state);
        $service = app(SizeAllocationService::class);
        $service->cancel($this->actor, $draft, $service->revision($draft));
        $this->assertSame(0.0, (float) $this->pool()->allocated_quantity);
        $broken = $this->draft('5');
        DB::table('inventory_size_allocations')->where('id', $broken->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);
        $this->assertSame('review', $this->pool()->allocation_state);
        $this->assertSame(0.0, (float) $this->pool()->allocated_quantity);
    }

    public function test_draft_cancelled_and_reversed_receipts_are_not_valid_received_evidence(): void
    {
        $draft = $this->receive('6', post: false);
        $this->assertSame(0, app(AllocationStockStatus::class)->query($this->actor)->count());
        DB::table('purchase_receipts')->where('id', $draft->id)->update(['status' => 'cancelled']);
        $this->assertSame(0, app(AllocationStockStatus::class)->query($this->actor)->count());
        $posted = $this->receive('10');
        $movement = $posted->items()->firstOrFail()->stock_movement_id;
        DB::table('stock_movements')->insert(['uuid' => (string) Str::uuid7(), 'variant_id' => $this->source->id, 'location_id' => $this->location->id,
            'type' => 'adjustment', 'quantity' => '-10', 'reverses_movement_id' => $movement, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('review', $this->pool()->allocation_state);
        $this->assertSame(0.0, (float) $this->pool()->received_quantity);
    }

    public function test_reservations_are_not_allocations_and_actions_use_only_free_stock(): void
    {
        $this->receive('10');
        StockBalance::where('variant_id', $this->source->id)->update(['quantity_reserved' => '10', 'quantity_available' => '0']);
        $this->assertSame('unallocated', $this->pool()->allocation_state);
        $this->assertSame('10.0000', $this->pool()->remaining_quantity);
        Livewire::test(AllocationStockTable::class)->assertTableActionHidden('allocateSizes', $this->pool());
        $draft = $this->draft('1');
        $this->rejectInventory(fn () => app(SizeAllocationService::class)->post($this->actor, $draft, app(SizeAllocationService::class)->revision($draft)));
        $this->assertSame(0.0, (float) $this->pool()->allocated_quantity);
    }

    public function test_pools_without_active_size_targets_offer_only_readonly_details(): void
    {
        $this->receive('10');
        $this->variant->update(['size_id' => null, 'color_id' => null]);
        $pool = $this->pool();
        $this->assertFalse((bool) $pool->has_size_targets);
        Livewire::test(AllocationStockTable::class)->assertTableActionHidden('allocateSizes', $pool)->assertTableActionVisible('poolDetails', $pool);
    }

    public function test_scoped_users_cannot_read_or_resolve_another_location_pool(): void
    {
        $this->receive('10');
        $foreign = Location::create(['name' => 'Other warehouse', 'type' => 'warehouse']);
        $this->receive('20', $foreign);
        $scoped = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $scoped->locations()->attach($this->location);
        app(LocationContext::class)->setFor($scoped, $this->location->id);
        $role = Role::create(['name' => 'supervisor', 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo([InventoryPermissions::ALLOCATIONS_VIEW, InventoryPermissions::BALANCES]);
        $scoped->assignRole($role);
        $this->actingAs($scoped);
        $page = Livewire::test(AllocationStockTable::class)->assertCountTableRecords(1)->filterTable('location_id', $foreign->id)->assertCountTableRecords(0);
        $this->assertNull($page->instance()->getTableRecord($this->source->id.'-'.$foreign->id));
        $page->filterTable('location_id', $this->location->id)->assertCountTableRecords(1)->assertTableActionHidden('allocateSizes', $this->poolFor($scoped));
    }

    private function poolFor(User $actor): ProductVariant
    {
        return app(AllocationStockStatus::class)->query($actor)->firstOrFail();
    }

    public function test_search_counts_and_arabic_english_rendering_use_the_same_classification(): void
    {
        $this->receive('10');
        foreach (['en' => 'Needs Allocation', 'ar' => 'يحتاج توزيع'] as $locale => $label) {
            app()->setLocale($locale);
            $page = Livewire::test(AllocationStockTable::class)->assertSuccessful()->assertSee($label);
            if ($locale === 'ar') {
                $page->assertSet('tableSearch', 'nothing matches')->assertCountTableRecords(0);
            }
            $page->searchTable('')->assertCountTableRecords(1);
            $this->assertSame(1, $page->instance()->counts()['unallocated']);
            $page->searchTable('nothing matches')->assertCountTableRecords(0);
            $this->assertSame(0, $page->instance()->counts()['needs']);
        }
    }

    public function test_multiple_colors_and_sizes_complete_one_pool_without_double_counting(): void
    {
        $this->receive('10');
        $other = ProductVariant::factory()->create(['product_id' => $this->source->product_id,
            'size_id' => Size::factory()->create(['name' => 'XL'])->id, 'color_id' => Color::factory()->create(['name' => 'Red'])->id]);
        $service = app(SizeAllocationService::class);
        $draft = $service->create($this->actor, ['product_id' => $this->source->product_id, 'location_id' => $this->location->id,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '4'], ['variant_id' => $other->id, 'quantity' => '6']]], (string) Str::uuid7());
        $service->post($this->actor, $draft, $service->revision($draft));

        $this->assertSame('full', $this->pool()->allocation_state);
        $this->assertSame(10.0, (float) $this->pool()->allocated_quantity);
        $this->assertSame('0.0000', $this->pool()->remaining_quantity);
    }

    public function test_missing_balance_and_reversed_allocation_evidence_require_review(): void
    {
        $this->receive('10');
        $document = $this->allocate('10');
        $movement = $document->items()->firstOrFail()->movements()->where('quantity', '<', 0)->firstOrFail();
        DB::table('stock_movements')->insert(['uuid' => (string) Str::uuid7(), 'variant_id' => $this->source->id, 'location_id' => $this->location->id,
            'type' => 'adjustment', 'quantity' => '10', 'reverses_movement_id' => $movement->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('review', $this->pool()->allocation_state);
        StockBalance::where('variant_id', $this->source->id)->delete();
        $this->assertNull($this->pool()->balance_id);
        $this->assertSame('review', $this->pool()->allocation_state);
        Livewire::test(AllocationStockTable::class)->assertCountTableRecords(1);
    }

    public function test_concurrent_allocation_attempts_preserve_the_actual_remaining_quantity(): void
    {
        $this->receive('10');
        $documents = [$this->draft('7'), $this->draft('7')];
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'mysql'
    || ! str_starts_with($db->getDatabaseName(), 'codex_public_ids_test_inventory_')
    || $db->selectOne('SELECT DATABASE() AS db')->db !== $db->getDatabaseName()) {
    throw new LogicException('An isolated Inventory database is required.');
}
$actor = App\Modules\AccessManagement\Models\User::findOrFail((int) getenv('ALLOCATION_TEST_ACTOR'));
$document = App\Modules\Inventory\Models\SizeAllocation::findOrFail((int) getenv('ALLOCATION_TEST_DOCUMENT'));
try {
    $service = app(App\Modules\Inventory\Services\SizeAllocationService::class);
    $service->post($actor, $document, $service->revision($document));
    echo 'posted';
} catch (Illuminate\Validation\ValidationException $error) {
    echo 'rejected';
}
PHP;
        $workers = [];
        try {
            foreach ($documents as $document) {
                $process = new Process([PHP_BINARY, '-r', $worker], base_path(), ['ALLOCATION_TEST_ACTOR' => (string) $this->actor->id, 'ALLOCATION_TEST_DOCUMENT' => (string) $document->id]);
                $process->setTimeout(45)->start();
                $workers[] = $process;
            }
            $results = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);
            $this->assertSame(['posted', 'rejected'], $results);
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        $this->assertSame('partial', $this->pool()->allocation_state);
        $this->assertSame(7.0, (float) $this->pool()->allocated_quantity);
        $this->assertSame('3.0000', $this->pool()->remaining_quantity);
    }

    public function test_allocate_link_prefills_the_existing_form_and_details_show_real_receipt_references(): void
    {
        $receipt = $this->receive('10');
        $pool = $this->pool();
        Livewire::test(AllocationStockTable::class)->mountTableAction('poolDetails', $pool)->assertMountedActionModalSee($receipt->receipt_number);
        Livewire::withQueryParams(['product' => $this->source->product_id, 'location' => $this->location->id])
            ->test(CreateSizeAllocation::class)
            ->assertSuccessful()->assertSet('data.product_id', $this->source->product_id)->assertSet('data.location_id', $this->location->id);
    }

    public function test_overallocation_is_rejected_without_changing_pool_or_valuation(): void
    {
        $this->receive('10');
        $before = DB::table('inventory_cost_states')->orderBy('id')->get()->toJson();
        $draft = $this->draft('11');
        $service = app(SizeAllocationService::class);

        $this->rejectInventory(fn () => $service->post($this->actor, $draft, $service->revision($draft)));
        $this->assertSame('unallocated', $this->pool()->allocation_state);
        $this->assertSame('10.0000', $this->pool()->remaining_quantity);
        $this->assertSame($before, DB::table('inventory_cost_states')->orderBy('id')->get()->toJson());
    }

    #[TestWith(['avco'])]
    #[TestWith(['standard'])]
    public function test_statuses_follow_value_conserving_allocations_for_other_costing_methods(string $method): void
    {
        InventoryValuationSetting::query()->update(['costing_method' => $method]);
        if ($method === 'standard') {
            foreach ([$this->source, $this->variant] as $variant) {
                $standard = InventoryStandardCost::create(['variant_id' => $variant->id, 'standard_cost' => '2']);
                InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => '2', 'effective_at' => now()->subDay()]);
            }
        }
        $this->receive('10');
        $this->allocate('4');
        $this->assertSame('partial', $this->pool()->allocation_state);
        $this->assertSame('6.0000', $this->pool()->remaining_quantity);
        $this->allocate('6');

        $this->assertSame('full', $this->pool()->allocation_state);
        $this->assertSame(10.0, (float) $this->pool()->allocated_quantity);
        $this->assertSame(20.0, (float) DB::table('inventory_cost_states')->where('location_id', $this->location->id)->sum('total_value'));
    }
}
