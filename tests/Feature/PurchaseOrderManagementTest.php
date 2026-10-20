<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\PurchasePriceHistory;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Taxes\Models\Tax;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PurchasingTestSetup;
use Tests\TestCase;

class PurchaseOrderManagementTest extends TestCase
{
    use PurchasingTestSetup;

    private User $actor;

    private Location $location;

    private Supplier $supplier;

    private ProductVariant $variant;

    private PurchaseOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchasingDatabase();
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'purchasing')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->location = Location::create(['name' => 'PO warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active'])->id,
            'sale_price' => '3000.0000', 'cost_price' => '1800.0000',
        ]);
        $this->service = app(PurchaseOrderService::class);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'supplier_id' => $this->supplier->id, 'location_id' => $this->location->id,
            'order_date' => '2026-10-13', 'expected_date' => '2026-10-20', 'notes' => null,
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '10', 'unit_price' => '2000', 'discount_amount' => '0', 'tax_amount' => '0', 'notes' => null]],
        ], $changes);
    }

    private function create(array $changes = []): PurchaseOrder
    {
        return $this->service->create($this->actor, $this->payload($changes), (string) Str::uuid7());
    }

    public function test_commercial_tables_show_purchase_controls_and_saved_snapshot_evidence(): void
    {
        $tax = Tax::factory()->create(['usage' => 'purchases', 'rate' => '0']);
        $this->variant->product->taxes()->attach($tax);
        $data = $this->payload();
        $data['items'][0] = ['tax_ids' => [$tax->id], 'discount_id' => null] + app(PurchaseOrderProductSelection::class)->formState($this->variant) + $data['items'][0];
        Livewire::test(CreatePurchaseOrder::class)->fillForm($data)
            ->assertSee('fi-fo-table-repeater', false)->assertSee(__('Discount'))->assertSee(__('Taxes'))
            ->call('create')->assertHasNoFormErrors();
        $order = PurchaseOrder::latest('id')->firstOrFail();
        Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid])
            ->assertSee(__('Discount'))->assertSee(__('Taxes'));
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])
            ->assertSee('data-commercial-snapshot="discount"', false)->assertSee('data-commercial-snapshot="tax"', false)->assertSee($tax->name);
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught);

            return;
        }
        $this->fail('Expected the operation to be rejected.');
    }

    private function scoped(array $permissions): User
    {
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($this->location);
        app(LocationContext::class)->setFor($actor, $this->location->id);
        $role = Role::create(['name' => 'po-role-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo($permissions);
        $actor->assignRole($role);
        $this->actingAs($actor);

        return $actor;
    }

    public function test_draft_updates_preserve_identity_allow_duplicate_variants_and_remove_lines(): void
    {
        $data = $this->payload();
        $data['items'][] = array_replace($data['items'][0], ['quantity' => '15', 'unit_price' => '2300']);
        $order = $this->service->create($this->actor, $data, (string) Str::uuid7());
        $this->assertSame('draft', $order->status);
        $this->assertSame('54500.0000', $order->total_amount);
        $ids = $order->items->modelKeys();
        $data['items'][0]['id'] = $ids[0];
        $data['items'][0]['quantity'] = '12';
        array_pop($data['items']);
        $updated = $this->service->update($this->actor, $order, $data, $this->service->revision($order));
        $this->assertSame([$ids[0]], $updated->items->modelKeys());
        $this->assertSame('24000.0000', $updated->total_amount);
        $this->assertSame($order->order_number, $updated->order_number);
        $this->assertDatabaseMissing('purchase_order_items', ['id' => $ids[1]]);
    }

    public function test_supplier_prices_remain_independent_after_confirmation_and_later_orders(): void
    {
        $first = $this->create();
        $first = $this->service->confirm($this->actor, $first, $this->service->revision($first));
        $original = $first->items()->first()->getAttributes();
        $other = Supplier::factory()->create();
        $second = $this->create(['supplier_id' => $other->id, 'items' => [['variant_id' => $this->variant->id, 'quantity' => '15', 'unit_price' => '2300']]]);
        $this->service->confirm($this->actor, $second, $this->service->revision($second));
        $this->assertSame($original, $first->items()->first()->getAttributes());
        $history = $this->service->history($this->actor)->where('variant_id', $this->variant->id)->orderBy('id')->get();
        $this->assertSame(['2000.000000', '2300.000000'], $history->pluck('unit_price')->all());
        $this->assertSame([$this->supplier->id, $other->id], $history->map(fn ($line) => $line->purchaseOrder->supplier_id)->all());
        $this->assertSame('1800.0000', $this->variant->fresh()->cost_price);
        $this->assertSame('3000.0000', $this->variant->fresh()->sale_price);
    }

    public function test_decimal_rounding_precision_and_header_line_aggregation(): void
    {
        $order = $this->create(['items' => [
            ['variant_id' => $this->variant->id, 'quantity' => '0.0001', 'unit_price' => '0.500000'],
            ['variant_id' => $this->variant->id, 'quantity' => '3.3333', 'unit_price' => '10.123456'],
        ]]);
        $this->assertSame('0.0001', $order->items[0]->subtotal);
        $this->assertSame('33.7445', $order->items[1]->subtotal);
        $this->assertSame('33.7446', $order->subtotal);
        $this->assertSame('0.0000', $order->discount_amount);
        $this->assertSame('0.0000', $order->tax_amount);
        $this->assertSame('33.7446', $order->total_amount);
        $calculator = app(PurchaseOrderCalculator::class);
        $this->assertSame('9999999999999999.9999', $calculator->decimal('9999999999999999.9999', 'amount'));
        $this->reject(fn () => $calculator->totals([['subtotal' => '9999999999999999.9999', 'discount_amount' => '0', 'tax_amount' => '0', 'line_total' => '9999999999999999.9999'], ['subtotal' => '1', 'discount_amount' => '0', 'tax_amount' => '0', 'line_total' => '1']]));
    }

    public function test_invalid_inputs_are_rejected_without_partial_documents(): void
    {
        foreach ([['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '1.00001'], ['quantity' => '100000000000000'],
            ['unit_price' => '-1'], ['unit_price' => '1e3'], ['unit_price' => '0.1234567'], ['unit_price' => 1.2], ['unit_price' => '1000000000000'],
            ['discount_amount' => '20001'], ['tax_amount' => '-1'], ['variant_id' => 999999], ['id' => 1]] as $invalid) {
            $data = $this->payload();
            $data['items'][0] = array_replace($data['items'][0], $invalid);
            $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        }
        foreach (['status', 'order_number', 'created_by', 'confirmed_by', 'confirmed_at', 'uuid', 'subtotal', 'tax_amount', 'discount_amount', 'total_amount'] as $field) {
            $this->reject(fn () => $this->create([$field => 'forged']));
        }
        $this->reject(fn () => $this->create(['expected_date' => '2026-10-01']));
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderItem::count());
    }

    public function test_creation_is_retry_safe_and_numbering_preserves_existing_numbers(): void
    {
        $token = (string) Str::uuid7();
        $order = $this->service->create($this->actor, $this->payload(), $token);
        $retry = $this->service->create($this->actor, $this->payload(), $token);
        $this->assertTrue($order->is($retry));
        $this->assertSame(1, $retry->items()->count());
        $this->reject(fn () => $this->service->create($this->actor, $this->payload(['notes' => 'different']), $token));
        $legacy = PurchaseOrder::factory()->create(['order_number' => 'PO-00001']);
        $numbers = [$order->order_number, $legacy->order_number];
        for ($index = 0; $index < 10; $index++) {
            $numbers[] = $this->create()->order_number;
        }
        $this->assertSame(count($numbers), count(array_unique($numbers)));
        $this->assertSame('PO-00001', $legacy->fresh()->order_number);
    }

    public function test_rfq_confirmation_audit_and_duplicate_confirmation_without_inventory_changes(): void
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '5', 'quantity_reserved' => '0', 'quantity_available' => '5']);
        $before = StockBalance::first()->getAttributes();
        $order = $this->create();
        $order = $this->service->markRfq($this->actor, $order, $this->service->revision($order));
        $this->assertSame('rfq', $order->status);
        $order = $this->service->resetDraft($this->actor, $order, $this->service->revision($order));
        $this->assertSame('draft', $order->status);
        $revision = $this->service->revision($order);
        $order = $this->service->confirm($this->actor, $order, $revision);
        $audit = [$order->confirmed_by, $order->confirmed_at->format('Y-m-d H:i:s')];
        $this->assertSame($this->actor->id, $audit[0]);
        $duplicate = $this->service->confirm($this->actor, $order, $revision);
        $this->assertSame($audit, [$duplicate->confirmed_by, $duplicate->confirmed_at->format('Y-m-d H:i:s')]);
        $this->assertSame($before, StockBalance::first()->getAttributes());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, PurchaseReceipt::count());
        $this->assertSame(0, DB::table('inventory_cost_entries')->count());
    }

    public function test_confirmation_rechecks_supplier_location_variant_activity_and_totals(): void
    {
        $empty = $this->create(['items' => []]);
        $this->reject(fn () => $this->service->confirm($this->actor, $empty, $this->service->revision($empty)));
        $order = $this->create();
        foreach ([$this->supplier, $this->variant, $this->variant->product, $this->location] as $model) {
            $model->update(['is_active' => false]);
            $this->reject(fn () => $this->service->confirm($this->actor, $order, $this->service->revision($order)));
            $model->update(['is_active' => true]);
        }
        $order->update(['total_amount' => '1']);
        $this->reject(fn () => $this->service->confirm($this->actor, $order, $this->service->revision($order)));
        $order->update(['total_amount' => '20000']);
        $order->items()->first()->update(['line_total' => '1']);
        $this->reject(fn () => $this->service->confirm($this->actor, $order, $this->service->revision($order)));
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_stale_updates_foreign_line_ids_and_duplicate_line_ids_are_rejected(): void
    {
        $order = $this->create();
        $revision = $this->service->revision($order);
        $other = $this->create();
        $data = $this->payload();
        $data['items'][0]['id'] = $other->items[0]->id;
        $this->reject(fn () => $this->service->update($this->actor, $order, $data, $revision));
        $data['items'][0]['id'] = $order->items[0]->id;
        $data['items'][] = $data['items'][0];
        $this->reject(fn () => $this->service->update($this->actor, $order, $data, $revision));
        $updated = $this->service->update($this->actor, $order, $this->payload(['notes' => 'New notes']), $revision);
        $this->reject(fn () => $this->service->update($this->actor, $order, $this->payload(['notes' => 'Stale notes']), $revision));
        $this->reject(fn () => $this->service->confirm($this->actor, $updated, $revision));
        $this->assertSame('New notes', $updated->fresh()->notes);
    }

    public function test_confirmed_documents_and_cancelled_documents_are_immutable(): void
    {
        $order = $this->create();
        $stale = $order->fresh();
        $order = $this->service->confirm($this->actor, $order, $this->service->revision($order));
        $this->reject(fn () => $this->service->update($this->actor, $order, $this->payload(), $this->service->revision($order)));
        $this->reject(fn () => $order->fresh()->update(['status' => 'draft']));
        $this->reject(fn () => $order->fresh()->update(['status' => 'received']));
        $this->reject(fn () => $stale->update(['supplier_id' => Supplier::factory()->create()->id]));
        $this->reject(fn () => $order->items()->first()->update(['unit_price' => '1']));
        $this->reject(fn () => $order->items()->first()->delete());
        $this->reject(fn () => $order->items()->create(['variant_id' => $this->variant->id, 'quantity' => '1', 'unit_price' => '1', 'line_total' => '1']));
        $this->reject(fn () => $this->service->resetDraft($this->actor, $order, $this->service->revision($order)));
        $receipt = $order->receipts()->firstOrFail();
        $receipts = app(PurchaseReceiptService::class);
        $receipts->cancel($this->actor, $receipt, $receipts->revision($receipt));
        $order = $this->service->cancel($this->actor, $order, $this->service->revision($order));
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->reject(fn () => $order->delete());
        $this->reject(fn () => $this->service->confirm($this->actor, $order, $this->service->revision($order)));
    }

    public function test_cancellation_is_retained_and_receiving_states_or_links_are_blocked(): void
    {
        foreach (['draft', 'rfq', 'confirmed'] as $status) {
            $order = $this->create();
            if ($status === 'rfq') {
                $order = $this->service->markRfq($this->actor, $order, $this->service->revision($order));
            } elseif ($status === 'confirmed') {
                $order = $this->service->confirm($this->actor, $order, $this->service->revision($order));
                $receipt = $order->receipts()->firstOrFail();
                $receipts = app(PurchaseReceiptService::class);
                $receipts->cancel($this->actor, $receipt, $receipts->revision($receipt));
            }
            $cancelled = $this->service->cancel($this->actor, $order, $this->service->revision($order));
            $this->assertSame('cancelled', $cancelled->status);
            $this->assertSame(1, $cancelled->items()->count());
        }
        foreach (['partially_received', 'received', 'closed'] as $status) {
            $order = PurchaseOrder::factory()->create(['status' => $status]);
            $this->reject(fn () => $this->service->cancel($this->actor, $order, $this->service->revision($order)));
        }
        $order = $this->create();
        PurchaseReceipt::factory()->create(['purchase_order_id' => $order->id, 'location_id' => $order->location_id]);
        $this->reject(fn () => $this->service->cancel($this->actor, $order, $this->service->revision($order)));
    }

    public function test_location_scope_panel_admission_direct_urls_and_service_authorization(): void
    {
        $allowed = $this->create();
        $otherLocation = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $hidden = $this->create(['location_id' => $otherLocation->id]);
        $actor = $this->scoped([PurchasingPermissions::ORDERS_VIEW]);
        $this->assertTrue($actor->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame([$allowed->id], PurchaseOrderResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$allowed->items[0]->id], $this->service->history($actor)->pluck('id')->all());
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $hidden]))->assertNotFound();
        $this->get(PurchaseOrderResource::getUrl('create'))->assertForbidden();
        $this->reject(fn () => $this->service->confirm($actor, $hidden, $this->service->revision($hidden)), HttpException::class);
        $this->reject(fn () => $this->service->create($actor, $this->payload(), (string) Str::uuid7()), HttpException::class);
        $actor = $this->scoped(PurchasingPermissions::all());
        $this->reject(fn () => $this->service->update($actor, $allowed, $this->payload(['location_id' => $otherLocation->id]), $this->service->revision($allowed)), HttpException::class);
        $this->reject(fn () => $this->service->create($actor, $this->payload(['location_id' => $otherLocation->id]), (string) Str::uuid7()), HttpException::class);
        $actor->locations()->detach($this->location);
        $this->assertSame(0, $this->service->history($actor)->count());
    }

    public function test_disabled_module_and_revoked_permissions_block_open_pages_and_services(): void
    {
        $actor = $this->scoped(PurchasingPermissions::all());
        $order = $this->service->create($actor, $this->payload(), (string) Str::uuid7());
        $page = Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid])->fillForm(['notes' => 'forged']);
        $role = $actor->roles->first();
        $role->revokePermissionTo(PurchasingPermissions::ORDERS_UPDATE);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $page->call('save')->assertForbidden();
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $this->reject(fn () => $this->service->confirm($this->actor, $order, $this->service->revision($order)), HttpException::class);
        $this->assertSame(0, $this->service->history($this->actor)->count());
        $this->get(PurchaseOrderResource::getUrl('history'))->assertForbidden();
    }

    public function test_filament_draft_edit_view_actions_and_read_only_history(): void
    {
        Livewire::test(CreatePurchaseOrder::class)->fillForm(array_replace_recursive($this->payload(), ['items' => [['product_id' => $this->variant->product_id, 'receiving_mode' => 'standard']]]))->call('create')->assertHasNoFormErrors();
        $order = PurchaseOrder::firstOrFail();
        $identity = $order->items()->first()->id;
        $page = Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid])->fillForm(['notes' => 'UI edit'])->call('save')->assertHasNoFormErrors();
        $page->fillForm(['notes' => 'Second UI edit'])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$identity], $order->items()->pluck('id')->all());
        $page->fillForm(['notes' => 'UI edit'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('UI edit', $order->fresh()->notes);
        Livewire::test(ListPurchaseOrders::class)->searchTable($order->order_number)->assertCanSeeTableRecords([$order]);
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->callAction('markRfq');
        $this->assertSame('rfq', $order->fresh()->status);
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->callAction('confirm');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->get(PurchaseOrderResource::getUrl('edit', ['record' => $order]))->assertForbidden();
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->assertActionHidden('edit')->assertActionDoesNotExist('close');
        Livewire::test(PurchasePriceHistory::class)->assertCanSeeTableRecords($order->items()->get())->filterTable('variant_id', $this->variant->id)->assertCanSeeTableRecords($order->items()->get());
    }

    public function test_filament_actions_require_separate_permissions_and_history_is_scoped(): void
    {
        $order = $this->create();
        $actor = $this->scoped([PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::ORDERS_UPDATE]);
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->assertActionHidden('confirm')->assertActionHidden('cancel')
            ->call('mountAction', 'confirm')->call('callMountedAction');
        $this->assertSame('draft', $order->fresh()->status);
        $this->reject(fn () => $this->service->confirm($actor, $order, $this->service->revision($order)), HttpException::class);
        Livewire::test(PurchasePriceHistory::class)->assertCanSeeTableRecords($order->items()->get());
    }

    public function test_stale_edit_pages_and_confirmation_modals_cannot_overwrite_new_values(): void
    {
        $order = $this->create();
        $page = Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid])->fillForm(['notes' => 'Stale UI']);
        $modal = Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->mountAction('confirm');
        $this->service->update($this->actor, $order, $this->payload(['notes' => 'Latest saved']), $this->service->revision($order));
        $page->call('save')->assertHasFormErrors(['revision']);
        $modal->callMountedAction()->assertHasErrors(['revision']);
        $this->assertSame('Latest saved', $order->fresh()->notes);
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_real_mariadb_concurrent_numbering_and_locking(): void
    {
        $this->markTestSkipped('This legacy placeholder does not exercise concurrent PO numbering. Receipt locking is tested separately with MariaDB workers.');
    }
}
