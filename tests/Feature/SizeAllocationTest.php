<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\CreateSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\EditSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\ViewSizeAllocation;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Inventory\Services\ProductInventorySummary;
use App\Modules\Inventory\Services\SizeAllocationPresentation;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\SizeAllocationValuationService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductVariantManager;
use App\Modules\Products\Services\UnallocatedVariantService;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\PurchasingPhase3TestSetup;
use Tests\TestCase;

class SizeAllocationTest extends TestCase
{
    use PurchasingPhase3TestSetup;

    private User $actor;

    private Location $location;

    private Product $product;

    private ProductVariant $source;

    private ProductVariant $target;

    private Supplier $supplier;

    private SizeAllocationService $allocations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3Database();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'purchasing', 'inventory', 'inventory_costing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->location = Location::create(['name' => 'Central Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->product = Product::factory()->create(['name' => 'Nike Air Force', 'is_active' => true, 'status' => 'active',
            'category_id' => Category::factory()->create()->id]);
        $this->target = ProductVariant::factory()->create(['product_id' => $this->product->id,
            'size_id' => Size::factory()->create(['name' => '40'])->id, 'cost_price' => '99', 'sale_price' => '300']);
        $this->source = app(UnallocatedVariantService::class)->ensure($this->actor, $this->product);
        InventoryValuationSetting::create(['category_id' => $this->product->category_id, 'costing_method' => 'fifo']);
        $this->supplier = Supplier::factory()->create();
        $this->allocations = app(SizeAllocationService::class);
    }

    private function receive(string $quantity = '300', string $cost = '200', ?ProductVariant $variant = null): void
    {
        $variant ??= $this->source;
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->create($this->actor, ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $variant->product_id,
                'receiving_mode' => $variant->isUnallocated() ? 'unknown' : 'known',
                'variant_id' => $variant->id, 'quantity' => $quantity, 'unit_price' => $cost]]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $this->assertNull($receipt->posted_at);
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
            'quantity' => $quantity, 'unit_cost' => $cost]]], $receipts->revision($receipt));
        $posted = $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        $receipts->post($this->actor, $posted, 'retry');
    }

    private function data(string $quantity = '100', ?ProductVariant $target = null): array
    {
        return ['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'items' => [['variant_id' => ($target ?? $this->target)->id, 'quantity' => $quantity]]];
    }

    private function draft(string $quantity = '100'): SizeAllocation
    {
        return $this->allocations->create($this->actor, $this->data($quantity), (string) Str::uuid7());
    }

    private function postAllocation(SizeAllocation $allocation): SizeAllocation
    {
        return $this->allocations->post($this->actor, $allocation, $this->allocations->revision($allocation));
    }

    private function state(ProductVariant $variant): InventoryCostState
    {
        return InventoryCostState::where('variant_id', $variant->id)->where('location_id', $this->location->id)->firstOrFail();
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);

            return;
        }
        $this->fail('Expected allocation rejection.');
    }

    private function method(string $method): void
    {
        InventoryValuationSetting::where('category_id', $this->product->category_id)->update(['costing_method' => $method]);
    }

    private function snapshot(): array
    {
        return collect(['inventory_size_allocations', 'inventory_size_allocation_items', 'stock_balances', 'stock_movements',
            'inventory_cost_states', 'inventory_cost_entries', 'document_numbers', 'document_number_sequences'])
            ->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function standard(ProductVariant $variant, string $cost): void
    {
        $standard = InventoryStandardCost::factory()->create(['variant_id' => $variant->id, 'standard_cost' => $cost]);
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id,
            'new_cost' => $cost, 'effective_at' => now()->subDay()]);
    }

    public function test_receiving_unallocated_pieces_uses_existing_idempotent_receipts(): void
    {
        $this->receive();
        $this->assertSame('300.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('60000.0000', $this->state($this->source)->total_value);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(0, SizeAllocation::count());
        $this->assertSame(0, StockBalance::where('variant_id', $this->target->id)->count());
        $this->assertSame($this->source->id, app(UnallocatedVariantService::class)->ensure($this->actor, $this->product)->id);
    }

    public function test_full_allocation_preserves_quantity_value_and_reference_prices(): void
    {
        $this->receive();
        $posted = $this->postAllocation($this->draft('300'));
        $this->assertSame('posted', $posted->status);
        $this->assertSame('0.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('0.0000', $this->state($this->source)->total_value);
        $this->assertSame('300.0000', $this->state($this->target)->on_hand_qty);
        $this->assertSame('60000.0000', $this->state($this->target)->total_value);
        $this->assertSame('99.0000', $this->target->refresh()->cost_price);
        $this->assertSame('300.0000', $this->target->sale_price);
        $this->assertSame(2, $posted->items->first()->movements()->count());
        $this->assertSame(2, InventoryCostEntry::where('entry_type', 'adjustment')->count());
    }

    public function test_partial_and_multiple_allocations_leave_only_the_remainder(): void
    {
        $this->receive();
        $this->postAllocation($this->draft('100'));
        $this->assertSame('200.0000', $this->state($this->source)->on_hand_qty);
        $this->postAllocation($this->draft('50'));
        $this->assertSame('150.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('150.0000', $this->state($this->target)->on_hand_qty);
        $this->assertSame('60000.0000', number_format((float) InventoryCostState::sum('total_value'), 4, '.', ''));
    }

    public function test_fifo_splits_multiple_cost_layers_and_keeps_original_provenance(): void
    {
        $this->receive('100', '200');
        $this->receive('200', '250');
        $originals = FifoLayer::orderBy('id')->get();
        $posted = $this->postAllocation($this->draft('150'));
        $this->assertSame('37500.0000', $this->state($this->source)->total_value);
        $this->assertSame('32500.0000', $this->state($this->target)->total_value);
        $layers = FifoLayer::where('variant_id', $this->target->id)->orderBy('id')->get();
        $this->assertSame(['100.0000', '50.0000'], $layers->pluck('quantity')->all());
        $this->assertSame(['200.000000', '250.000000'], $layers->pluck('unit_cost')->all());
        $this->assertSame($originals->modelKeys(), $layers->pluck('source_layer_id')->all());
        $this->assertTrue($layers[0]->received_at->equalTo($originals[0]->received_at));
        $this->assertSame(2, FifoLayerConsumption::count());
        $this->assertSame(4, $posted->items->first()->movements()->count());
        $this->assertSame(0, InventoryCostEntry::where('entry_type', 'issue')->count());
    }

    public function test_fifo_distributes_across_multiple_sizes_without_double_counting(): void
    {
        $this->receive();
        $other = ProductVariant::factory()->create(['product_id' => $this->product->id, 'size_id' => Size::factory()->create()->id]);
        $data = $this->data('40');
        $data['items'][] = ['variant_id' => $other->id, 'quantity' => '60'];
        $this->postAllocation($this->allocations->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertSame('200.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('40.0000', $this->state($this->target)->on_hand_qty);
        $this->assertSame('60.0000', $this->state($other)->on_hand_qty);
    }

    public function test_avco_merges_with_existing_destination_stock_and_preserves_value(): void
    {
        $this->method('avco');
        $this->receive('100', '200');
        $this->receive('200', '250');
        $this->receive('50', '100', $this->target);
        $this->postAllocation($this->draft('150'));
        $this->assertSame('35000.0000', $this->state($this->source)->total_value);
        $this->assertSame('40000.0000', $this->state($this->target)->total_value);
        $this->assertSame('200.000000', $this->state($this->target)->avg_cost);
        $this->assertSame('200.0000', $this->state($this->target)->on_hand_qty);
    }

    public function test_avco_rounding_transfers_all_residual_value_on_final_allocation(): void
    {
        $this->method('avco');
        $this->receive('3', '0.333333');
        $this->postAllocation($this->draft('1'));
        $this->postAllocation($this->draft('1'));
        $this->postAllocation($this->draft('1'));
        $this->assertSame('0.0000', $this->state($this->source)->total_value);
        $this->assertSame('1.0000', $this->state($this->target)->total_value);
    }

    public function test_standard_cost_variance_is_rejected_atomically(): void
    {
        $this->method('standard');
        $this->standard($this->source, '200');
        $this->standard($this->target, '250');
        $this->receive();
        $draft = $this->draft();
        $this->reject(fn () => $this->postAllocation($draft));
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertSame('60000.0000', $this->state($this->source)->total_value);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(0, InventoryCostState::where('variant_id', $this->target->id)->count());
    }

    public function test_matching_standard_costs_transfer_value_with_revision_audit(): void
    {
        $this->method('standard');
        $this->standard($this->source, '200');
        $this->standard($this->target, '200');
        $this->receive();
        $this->postAllocation($this->draft());
        $this->assertSame('40000.0000', $this->state($this->source)->total_value);
        $this->assertSame('20000.0000', $this->state($this->target)->total_value);
        $this->assertSame(2, InventoryCostEntry::where('entry_type', 'adjustment')->whereNotNull('standard_cost_revision_id')->count());
    }

    public function test_standard_hardening_repeated_receipts_allow_value_conserving_partial_and_full_allocation(): void
    {
        $this->method('standard');
        $this->standard($this->source, '0.333333');
        $this->standard($this->target, '0.333333');
        foreach (['1', '1', '1'] as $quantity) {
            $this->receive($quantity, '0.333333');
        }
        foreach ([['0.6667', '0.3333'], ['0.3333', '0.6667'], ['0.0000', '1.0000']] as [$sourceValue, $targetValue]) {
            $allocation = $this->postAllocation($this->draft('1'));
            $this->assertSame($sourceValue, $this->state($this->source)->total_value);
            $this->assertSame($targetValue, $this->state($this->target)->total_value);
            $beforeReplay = $this->snapshot();
            $this->postAllocation($allocation);
            $this->assertSame($beforeReplay, $this->snapshot());
        }
        $this->assertSame('0.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('3.0000', $this->state($this->target)->on_hand_qty);
        $this->assertSame(6, InventoryCostEntry::where('entry_type', 'adjustment')->whereNotNull('standard_cost_revision_id')->count());
    }

    public function test_standard_hardening_rounding_variance_split_is_rejected_without_any_posting(): void
    {
        $this->method('standard');
        $this->standard($this->source, '0.333333');
        $this->standard($this->target, '0.333333');
        $this->receive('3', '0.333333');
        $this->receive('1', '0.333333', $this->target);
        $allocation = $this->draft('1');
        $before = $this->snapshot();
        $this->reject(fn () => $this->postAllocation($allocation));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_standard_hardening_late_incompatible_target_rolls_back_earlier_transfers(): void
    {
        $this->method('standard');
        $this->standard($this->source, '0.333333');
        $this->standard($this->target, '0.333333');
        $other = ProductVariant::factory()->create(['product_id' => $this->product->id, 'size_id' => Size::factory()->create()->id]);
        $this->standard($other, '0.333333');
        $this->receive('3', '0.333333');
        $data = $this->data('1');
        $data['items'][] = ['variant_id' => $other->id, 'quantity' => '1'];
        $allocation = $this->allocations->create($this->actor, $data, (string) Str::uuid7());
        $before = $this->snapshot();
        $this->reject(fn () => $this->postAllocation($allocation));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_standard_hardening_offsetting_line_rounding_allows_a_conserving_document(): void
    {
        $this->method('standard');
        $this->standard($this->source, '0.333333');
        $this->standard($this->target, '0.333333');
        $other = ProductVariant::factory()->create(['product_id' => $this->product->id, 'size_id' => Size::factory()->create()->id]);
        $this->standard($other, '0.333333');
        $this->receive('9', '0.333333');
        $this->receive('1', '0.333333', $this->target);
        $this->receive('2', '0.333333', $other);
        $data = $this->data('1');
        $data['items'][] = ['variant_id' => $other->id, 'quantity' => '1'];
        $this->postAllocation($this->allocations->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertSame('7.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('2.3333', $this->state($this->source)->total_value);
        $this->assertSame('0.6667', $this->state($this->target)->total_value);
        $this->assertSame('1.0000', $this->state($other)->total_value);
        $this->assertSame(4, InventoryCostEntry::where('entry_type', 'adjustment')->count());
    }

    public function test_overallocation_and_reserved_stock_are_rejected(): void
    {
        $this->receive();
        $this->reject(fn () => $this->postAllocation($this->draft('301')));
        StockBalance::where('variant_id', $this->source->id)->update(['quantity_reserved' => '250', 'quantity_available' => '50']);
        $this->reject(fn () => $this->postAllocation($this->draft('100')));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_invalid_cross_product_duplicate_unsized_and_fractional_targets_are_rejected(): void
    {
        $other = ProductVariant::factory()->create();
        $this->reject(fn () => $this->allocations->create($this->actor, $this->data('1', $other), 'cross'));
        $this->reject(fn () => $this->allocations->create($this->actor, $this->data('1', $this->source), 'source'));
        foreach (['0', '-1', '1.5'] as $quantity) {
            $this->reject(fn () => $this->draft($quantity));
        }
        $data = $this->data();
        $data['items'][] = $data['items'][0];
        $this->reject(fn () => $this->allocations->create($this->actor, $data, 'duplicate'));
    }

    public function test_wrong_location_does_not_borrow_source_stock(): void
    {
        $this->receive();
        $data = $this->data();
        $data['location_id'] = Location::create(['name' => 'Other warehouse', 'type' => 'warehouse', 'is_active' => true])->id;
        $draft = $this->allocations->create($this->actor, $data, 'wrong-location');
        $this->reject(fn () => $this->postAllocation($draft));
        $this->assertSame('300.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_post_retry_and_draft_submission_retry_are_idempotent(): void
    {
        $this->receive();
        $draft = $this->allocations->create($this->actor, $this->data(), 'submission');
        $same = $this->allocations->create($this->actor, $this->data(), 'submission');
        $this->assertSame($draft->id, $same->id);
        $posted = $this->postAllocation($draft);
        $this->assertSame($posted->id, $this->allocations->post($this->actor, $draft, 'stale-retry')->id);
        $this->assertSame(3, StockMovement::count());
        $this->reject(fn () => $this->allocations->create($this->actor, $this->data('99'), 'submission'));
    }

    public function test_two_outstanding_drafts_cannot_spend_the_same_source_balance(): void
    {
        $this->receive();
        $first = $this->draft('200');
        $second = $this->draft('200');
        $this->postAllocation($first);
        $this->reject(fn () => $this->postAllocation($second));
        $this->assertSame('100.0000', $this->state($this->source)->on_hand_qty);
    }

    public function test_edit_cancel_and_stale_revision_are_controlled(): void
    {
        $this->receive();
        $draft = $this->draft();
        $revision = $this->allocations->revision($draft);
        $updated = $this->allocations->update($this->actor, $draft, $this->data('50'), $revision);
        $this->reject(fn () => $this->allocations->post($this->actor, $updated, $revision));
        $this->allocations->cancel($this->actor, $updated, $this->allocations->revision($updated));
        $this->reject(fn () => $this->postAllocation($updated->refresh()));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_posted_documents_lines_and_valuations_are_immutable(): void
    {
        $this->receive();
        $posted = $this->postAllocation($this->draft());
        $this->reject(fn () => $posted->update(['notes' => 'changed']));
        $this->reject(fn () => $posted->items()->first()->update(['quantity' => '2']));
        $entry = InventoryCostEntry::where('entry_type', 'adjustment')->firstOrFail();
        $this->reject(fn () => $entry->update(['total_cost' => '0']));
    }

    public function test_location_permissions_are_enforced_for_service_and_resource(): void
    {
        $this->receive();
        $draft = $this->draft();
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->location);
        app(LocationContext::class)->setFor($user, $this->location->id);
        $role = Role::create(['name' => 'allocator', 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo([InventoryPermissions::ALLOCATIONS_VIEW, InventoryPermissions::ALLOCATIONS_MANAGE]);
        $user->assignRole($role);
        $this->actingAs($user);
        $this->assertFalse(Gate::forUser($user)->allows('post', $draft));
        $this->reject(fn () => $this->allocations->post($user, $draft, $this->allocations->revision($draft)), HttpException::class);
        $other = Location::create(['name' => 'Forbidden warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $data = $this->data();
        $data['location_id'] = $other->id;
        $this->reject(fn () => $this->allocations->create($user, $data, 'forbidden'), HttpException::class);
    }

    public function test_missing_size_variant_uses_catalog_manager_and_generated_identifiers(): void
    {
        $size = Size::factory()->create(['name' => '41']);
        $variant = app(ProductVariantManager::class)->save($this->actor, $this->product, ['size_id' => $size->id]);
        $this->assertNotEmpty($variant->sku);
        $this->assertNotEmpty($variant->barcode);
        $this->reject(fn () => app(ProductVariantManager::class)->save($this->actor, $this->product, ['size_id' => $size->id]));
        $this->reject(fn () => app(ProductVariantManager::class)->save($this->actor, $this->product, ['size_id' => $size->id], $this->source));
    }

    public function test_simple_existing_variant_is_never_silently_reinterpreted(): void
    {
        $simple = ProductVariant::factory()->create();
        $this->reject(fn () => app(UnallocatedVariantService::class)->ensure($this->actor, $simple->product));
    }

    private function workers(array $allocations): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the isolated MariaDB runner; SQLite does not prove row locking.');
        }
        $configuration = config('database.connections.mysql');
        $database = DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $database);
        $processes = [];
        $start = microtime(true) + 2;
        try {
            foreach ($allocations as $allocation) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/SizeAllocationWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'ALLOCATION_ACTOR' => (string) $this->actor->id, 'ALLOCATION_ID' => (string) $allocation->id,
                    'ALLOCATION_REVISION' => $this->allocations->revision($allocation), 'ALLOCATION_START' => (string) $start,
                ]);
                $process->setTimeout(40)->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Isolated allocation worker failed.');
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

    public function test_concurrent_duplicate_posts_create_one_reclassification(): void
    {
        $this->receive();
        $draft = $this->draft();
        $results = $this->workers([$draft, $draft]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(3, StockMovement::count());
        $this->assertSame('200.0000', $this->state($this->source)->on_hand_qty);
    }

    public function test_concurrent_allocations_cannot_overspend_the_source(): void
    {
        $this->receive();
        $results = $this->workers([$this->draft('200'), $this->draft('200')]);
        $this->assertEqualsCanonicalizing(['posted', 'rejected'], array_column($results, 'status'));
        $this->assertSame('100.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('200.0000', $this->state($this->target)->on_hand_qty);
        $this->assertSame(3, StockMovement::count());
    }

    public function test_receiving_fractional_unallocated_pieces_is_rejected_without_stock(): void
    {
        $this->reject(fn () => $this->receive('1.5'));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_zero_grid_rows_are_ignored_during_partial_allocation(): void
    {
        $this->receive();
        $other = ProductVariant::factory()->create(['product_id' => $this->product->id, 'size_id' => Size::factory()->create()->id]);
        $data = $this->data('100');
        $data['items'][] = ['variant_id' => $other->id, 'quantity' => '0'];
        $allocation = $this->allocations->create($this->actor, $data, 'zero-grid');
        $this->assertCount(1, $allocation->items);
        $this->postAllocation($allocation);
        $this->assertSame('200.0000', $this->state($this->source)->on_hand_qty);
    }

    public function test_product_inventory_summary_groups_sizes_by_location(): void
    {
        $this->receive();
        $this->postAllocation($this->draft());
        $summary = app(ProductInventorySummary::class)->forProduct($this->actor, $this->product, $this->location->id);
        $this->assertCount(1, $summary['locations']);
        $this->assertSame('300.0000', $summary['locations'][0]['total']);
        $this->assertSame('200.0000', $summary['locations'][0]['unallocated']);
        $this->assertCount(2, $summary['locations'][0]['rows']);
        $this->assertStringContainsString('Nike Air Force', view('components.product-inventory-summary', $summary)->render());
    }

    public function test_allocation_pages_render_and_post_through_the_service(): void
    {
        $this->receive();
        $draft = $this->draft();
        $namespace = 'App\\Modules\\Inventory\\Filament\\Resources\\SizeAllocations\\Pages\\';
        Livewire::test($namespace.'ListSizeAllocations')->assertOk()->assertCanSeeTableRecords([$draft]);
        Livewire::test($namespace.'CreateSizeAllocation')->assertOk();
        Livewire::test($namespace.'EditSizeAllocation', ['record' => $draft->uuid])->assertOk();
        Livewire::test($namespace.'ViewSizeAllocation', ['record' => $draft->uuid])
            ->assertOk()->callAction('post')->assertHasNoActionErrors();
        $this->assertSame('posted', $draft->refresh()->status);
    }

    public function test_create_page_saves_the_size_grid_through_the_service(): void
    {
        $this->receive();
        Livewire::test(CreateSizeAllocation::class)
            ->fillForm($this->data('40'))->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, SizeAllocation::count());
        $this->assertSame('40.0000', SizeAllocation::firstOrFail()->items->first()->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_confirmation_explains_insufficient_stock_in_arabic_and_english_without_posting(): void
    {
        $this->receive('360');
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            $draft = $this->draft('500');
            Livewire::test(ViewSizeAllocation::class, ['record' => $draft->uuid])
                ->mountAction('post')->callMountedAction()
                ->assertNotified(Notification::make()->title(__('Cannot post size allocation'))
                    ->body(__('Allocation exceeds available unallocated stock at this location.'))->danger())
                ->assertActionMounted('post');
            $this->assertSame('draft', $draft->refresh()->status);
            $this->assertSame('360.0000', $this->state($this->source)->on_hand_qty);
            $this->assertSame('72000.0000', $this->state($this->source)->total_value);
            $this->assertSame(1, StockMovement::count());
            $this->assertSame(1, StockBalance::count());
            $this->assertSame(1, InventoryCostState::count());
        }
    }

    public function test_stale_post_and_cancel_confirmations_explain_the_conflict(): void
    {
        app()->setLocale('en');
        session(['locale' => 'en']);
        $this->receive();
        foreach (['post', 'cancel'] as $method) {
            $draft = $this->draft();
            $page = Livewire::test(ViewSizeAllocation::class, ['record' => $draft->uuid])->mountAction($method);
            $this->allocations->update($this->actor, $draft, $this->data('50'), $this->allocations->revision($draft));
            $page->callMountedAction()->assertNotified(Notification::make()
                ->title(__($method === 'post' ? 'Cannot post size allocation' : 'Cannot cancel size allocation'))
                ->body(__('This allocation changed. Reload before continuing.'))->danger());
            $this->assertSame('draft', $draft->refresh()->status);
            $this->assertSame(1, StockMovement::count());
        }
    }

    public function test_unexpected_posting_failure_is_reported_with_a_safe_visible_message(): void
    {
        app()->setLocale('en');
        session(['locale' => 'en']);
        $this->receive();
        $draft = $this->draft();
        Exceptions::fake();
        $this->mock(SizeAllocationValuationService::class)
            ->shouldReceive('post')->once()->andThrow(new \RuntimeException('Private fault details'));
        Livewire::test(ViewSizeAllocation::class, ['record' => $draft->uuid])->mountAction('post')->callMountedAction()
            ->assertNotified(Notification::make()->title(__('Cannot post size allocation'))
                ->body(__('The allocation operation failed. Refresh the page and try again.'))->danger());
        Exceptions::assertReported(\RuntimeException::class);
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_valid_confirmation_posts_and_displays_success(): void
    {
        app()->setLocale('en');
        session(['locale' => 'en']);
        $this->receive();
        $draft = $this->draft();
        Livewire::test(ViewSizeAllocation::class, ['record' => $draft->uuid])->mountAction('post')->callMountedAction()
            ->assertNotified(Notification::make()->title(__('Size allocation posted'))->success())
            ->assertActionNotMounted('post');
        $this->assertSame('posted', $draft->refresh()->status);
        $this->assertSame('200.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('100.0000', $this->state($this->target)->on_hand_qty);
    }

    public function test_late_posting_failure_rolls_back_every_stock_and_cost_change(): void
    {
        $this->receive('100', '200');
        $this->receive('200', '250');
        $draft = $this->draft('150');
        $original = app(InventoryPostingService::class);
        $calls = 0;
        $mock = \Mockery::mock(InventoryPostingService::class);
        $mock->shouldReceive('postMany')->andReturnUsing(function (array $commands, User $actor) use ($original, &$calls): array {
            if (++$calls === 2) {
                throw ValidationException::withMessages(['posting' => 'Injected second-layer failure.']);
            }

            return $original->postMany($commands, $actor);
        });
        $this->app->instance(InventoryPostingService::class, $mock);
        $this->reject(fn () => $this->postAllocation($draft));
        $this->assertSame(2, $calls);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(2, FifoLayer::count());
        $this->assertSame(0, FifoLayerConsumption::count());
        $this->assertSame('70000.0000', $this->state($this->source)->total_value);
        $this->assertSame('300.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('draft', $draft->refresh()->status);
    }

    public function test_unreconciled_valuation_and_changed_methods_are_rejected(): void
    {
        $this->receive();
        $this->state($this->source)->update(['on_hand_qty' => '299']);
        $this->reject(fn () => $this->postAllocation($this->draft()));
        $this->state($this->source)->update(['on_hand_qty' => '300']);
        $this->method('avco');
        $this->reject(fn () => $this->postAllocation($this->draft()));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_arabic_and_english_allocation_labels_and_permissions_exist(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $translations = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach (['Allocate Sizes', 'Size Allocations', 'Unallocated pieces', 'Post Allocation',
                InventoryPermissions::ALLOCATIONS_VIEW, InventoryPermissions::ALLOCATIONS_MANAGE, InventoryPermissions::ALLOCATIONS_POST] as $key) {
                $this->assertArrayHasKey($key, $translations);
                if ($locale === 'ar') {
                    $this->assertNotSame($key, $translations[$key]);
                }
            }
        }
    }

    public function test_create_confirmation_posts_through_the_existing_allocation_service(): void
    {
        $this->receive('24');
        Livewire::test(CreateSizeAllocation::class)->fillForm($this->data('24'))->call('createAndPost')->assertHasNoFormErrors();
        $allocation = SizeAllocation::firstOrFail();
        $this->assertSame('posted', $allocation->status);
        $this->assertSame('0.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame('24.0000', $this->state($this->target)->on_hand_qty);
    }

    public function test_create_confirmation_failure_rolls_back_the_new_draft_and_all_posting(): void
    {
        $this->receive('24');
        $this->mock(SizeAllocationValuationService::class)->shouldReceive('post')->once()
            ->andThrow(ValidationException::withMessages(['source' => 'Posting failed safely.']));
        Livewire::test(CreateSizeAllocation::class)->fillForm($this->data('24'))->call('createAndPost')->assertHasFormErrors();
        $this->assertSame(0, SizeAllocation::count());
        $this->assertSame('24.0000', $this->state($this->source)->on_hand_qty);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_edit_confirmation_saves_pending_quantities_then_posts_in_one_transaction(): void
    {
        $this->receive('24');
        $draft = $this->draft('6');
        Livewire::test(EditSizeAllocation::class, ['record' => $draft->uuid])
            ->fillForm(['items' => [['variant_id' => $this->target->id, 'quantity' => '8']], 'notes' => 'Reviewed allocation'])
            ->call('saveAndPost')->assertHasNoFormErrors();
        $this->assertSame('posted', $draft->fresh()->status);
        $this->assertSame('8.0000', $draft->fresh()->items->first()->quantity);
        $this->assertSame('16.0000', $this->state($this->source)->on_hand_qty);
    }

    public function test_summary_uses_real_posted_receipt_and_hides_it_when_purchasing_is_disabled(): void
    {
        $this->receive('24');
        $presentation = app(SizeAllocationPresentation::class);
        $context = $presentation->context($this->actor, ['brand_id' => 'none'] + $this->data('4'));
        $this->assertNotNull($context['receipt']);
        $this->assertSame('posted', $context['receipt']->status);
        $this->assertSame($this->location->id, $context['receipt']->location_id);
        $this->assertSame('4800.0000', $context['cost']->total_value);
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $this->assertNull($presentation->context($this->actor, ['brand_id' => 'none'] + $this->data('4'))['receipt']);
    }
}
