<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\CreateSalesDelivery;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\EditSalesDelivery;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ListSalesDeliveries;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ViewSalesDelivery;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesFulfillmentService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesDeliveryManagementTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesDeliveryService $service;

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
        Module::where('code', 'sales')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        $this->branch = Location::create(['name' => 'Delivery branch', 'type' => 'branch', 'is_active' => true]);
        $product = Product::factory()->create(['status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->branch->id,
            'quantity_on_hand' => '100', 'quantity_reserved' => '0', 'quantity_available' => '100']);
        $this->service = app(SalesDeliveryService::class);
    }

    private function order(array $quantities = ['10']): SalesOrder
    {
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'items' => array_map(fn ($quantity): array => ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'quantity' => $quantity, 'unit_price' => '10', 'discount_amount' => '0', 'tax_amount' => '0'], $quantities)], (string) Str::uuid7());

        return app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
    }

    private function payload(SalesOrder $order, string $quantity = '3', ?int $lineId = null): array
    {
        return ['sales_order_id' => $order->id, 'items' => [['sales_order_item_id' => $lineId ?? $order->items()->firstOrFail()->id, 'quantity' => $quantity]]];
    }

    private function delivery(SalesOrder $order, string $quantity = '3'): SalesDelivery
    {
        return $this->service->create($this->actor, $this->payload($order, $quantity), (string) Str::uuid7());
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

    private function inventorySnapshot(): array
    {
        return collect(['stock_balances', 'sales_stock_reservations', 'stock_movements', 'inventory_cost_entries', 'inventory_cost_states', 'fifo_layers', 'fifo_layer_consumptions'])
            ->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_partial_and_multiple_drafts_leave_inventory_and_fulfillment_unchanged(): void
    {
        $order = $this->order();
        $before = $this->inventorySnapshot();
        $first = $this->delivery($order, '7');
        $second = $this->delivery($order, '7');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('7.0000', $first->items->first()->quantity);
        $this->assertSame('10.0000', app(SalesFulfillmentService::class)->quantities($order)[$order->items->first()->id]['remaining']);
        $first = $this->service->update($this->actor, $first, $this->payload($order, '5'), $this->service->revision($first));
        $this->service->cancel($this->actor, $first, $this->service->revision($first));
        $this->assertSame($before, $this->inventorySnapshot());
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_fulfillment_counts_posted_only_and_rejects_overdelivery(): void
    {
        $order = $this->order();
        $posted = $this->delivery($order, '4.1234');
        // Historical evidence fixture only; Phase 3A has no posting service.
        DB::table('sales_deliveries')->where('id', $posted->id)->update(['status' => 'posted', 'posted_at' => now()]);
        $this->delivery($order, '5');
        $cancelled = $this->delivery($order, '5');
        $this->service->cancel($this->actor, $cancelled, $this->service->revision($cancelled));
        $this->assertSame(['ordered' => '10.0000', 'delivered' => '4.1234', 'remaining' => '5.8766'],
            app(SalesFulfillmentService::class)->quantities($order)[$order->items->first()->id]);
        $this->reject(fn () => $this->delivery($order, '5.8767'));
        $this->assertSame('5.8766', $this->delivery($order, '5.8766')->items->first()->quantity);
    }

    public function test_duplicate_variants_are_limited_per_order_line_and_duplicate_item_ids_are_rejected(): void
    {
        $order = $this->order(['2.1000', '3.2000']);
        $items = $order->items()->orderBy('id')->get();
        $payload = ['sales_order_id' => $order->id, 'items' => [
            ['sales_order_item_id' => $items[0]->id, 'quantity' => '2.1'],
            ['sales_order_item_id' => $items[1]->id, 'quantity' => '3.2']]];
        $before = $this->inventorySnapshot();
        $delivery = $this->service->create($this->actor, $payload, (string) Str::uuid7());
        $this->assertCount(2, $delivery->items);
        $payload['items'][0]['quantity'] = '2.1001';
        $this->reject(fn () => $this->service->create($this->actor, $payload, (string) Str::uuid7()));
        $payload['items'][0]['quantity'] = '1';
        $payload['items'][1]['sales_order_item_id'] = $items[0]->id;
        $this->reject(fn () => $this->service->create($this->actor, $payload, (string) Str::uuid7()));
        $this->assertSame($before, $this->inventorySnapshot());
    }

    public function test_foreign_invalid_unallocated_lines_and_excess_decimal_precision_are_rejected(): void
    {
        $order = $this->order();
        $other = $this->order();
        $this->reject(fn () => $this->service->create($this->actor, $this->payload($order, '1', $other->items->first()->id), (string) Str::uuid7()));
        foreach (['0', '-1', '0.00001', '1e1', '100000000000000', '10.0001'] as $quantity) {
            $this->reject(fn () => $this->delivery($order, $quantity));
        }
        $this->assertSame('0.0001', $this->delivery($order, '0.0001')->items->first()->quantity);
        $this->variant->update(['is_active' => false]);
        $this->reject(fn () => $this->delivery($order));
        DB::table('product_variants')->where('id', $this->variant->id)->update(['is_active' => true, 'unallocated_product_id' => $this->variant->product_id]);
        $this->reject(fn () => $this->delivery($order));
    }

    public function test_stale_edits_and_foreign_delivery_line_ids_are_atomic(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order);
        $revision = $this->service->revision($delivery);
        $changed = $this->service->update($this->actor, $delivery, $this->payload($order, '4'), $revision);
        $this->reject(fn () => $this->service->update($this->actor, $delivery, $this->payload($order, '5'), $revision));
        $foreign = $this->delivery($order);
        $payload = $this->payload($order, '2');
        $payload['items'][0]['id'] = $foreign->items->first()->id;
        $this->reject(fn () => $this->service->update($this->actor, $changed, $payload, $this->service->revision($changed)));
        $this->assertSame('4.0000', $changed->items()->first()->quantity);
    }

    public function test_identity_lifecycle_and_quiet_model_writes_are_guarded(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order);
        foreach (['status' => 'posted', 'location_id' => 999, 'sales_order_id' => 999, 'posted_by' => $this->actor->id, 'delivery_number' => 'changed'] as $field => $value) {
            $this->reject(fn () => $delivery->fresh()->forceFill([$field => $value])->saveQuietly());
        }
        $item = $delivery->items->first();
        $this->reject(fn () => $item->fresh()->forceFill(['stock_movement_id' => 999])->saveQuietly());
        $this->reject(fn () => $item->fresh()->forceFill(['sales_order_item_id' => 999])->saveQuietly());
        $this->reject(fn () => $delivery->delete());
        $delivery = $this->service->cancel($this->actor, $delivery, $this->service->revision($delivery));
        $this->reject(fn () => $delivery->forceFill(['notes' => 'changed'])->saveQuietly());
        $this->reject(fn () => $item->fresh()->forceFill(['quantity' => '1'])->saveQuietly());
        $this->reject(fn () => $item->fresh()->delete());
        $this->reject(fn () => $item->fresh()->increment('quantity'));
        $this->reject(fn () => $item->fresh()->incrementQuietly('quantity'));
        $this->reject(fn () => $item->fresh()->incrementEach(['quantity' => 1]));
        $this->reject(fn () => $this->service->update($this->actor, $delivery, $this->payload($order), $this->service->revision($delivery)));
    }

    public function test_cancelled_draft_allows_parent_cancellation_and_retry_preserves_audit(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order);
        $this->assertTrue($order->hasDownstreamDocuments());
        $this->reject(fn () => app(SalesOrderConfirmationService::class)->cancel($this->actor, $order));
        $revision = $this->service->revision($delivery);
        $cancelled = $this->service->cancel($this->actor, $delivery, $revision);
        $this->assertSame($cancelled->getAttributes(), $this->service->cancel($this->actor, $delivery, $revision)->getAttributes());
        $this->assertSame($this->actor->id, $cancelled->cancelled_by);
        $this->assertFalse($order->hasDownstreamDocuments());
        $this->assertTrue(Gate::allows('cancel', $order->fresh()));
        $this->assertSame('cancelled', app(SalesOrderConfirmationService::class)->cancel($this->actor, $order)->status);
        $this->assertSame('10.0000', $order->reservations()->first()->quantity_released);
        $this->assertDatabaseCount('sales_delivery_items', 1);
    }

    public function test_posted_and_corrupted_cancelled_deliveries_remain_blockers(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order);
        $this->service->cancel($this->actor, $delivery, $this->service->revision($delivery));
        DB::table('sales_deliveries')->where('id', $delivery->id)->update(['notes' => 'tampered']);
        $this->assertTrue($order->hasDownstreamDocuments());
        $this->reject(fn () => app(SalesOrderConfirmationService::class)->cancel($this->actor, $order));
        DB::table('sales_deliveries')->where('id', $delivery->id)->update(['status' => 'posted']);
        $this->reject(fn () => $this->service->cancel($this->actor, $delivery, $this->service->revision($delivery->fresh())));
        $this->reject(fn () => $delivery->fresh()->forceFill(['notes' => 'x'])->saveQuietly());
    }

    public function test_submission_replay_and_immutable_order_branch_with_database_ownership(): void
    {
        $order = $this->order();
        $submission = (string) Str::uuid7();
        $payload = $this->payload($order);
        $delivery = $this->service->create($this->actor, $payload, $submission);
        $this->assertSame($delivery->id, $this->service->create($this->actor, $payload, $submission)->id);
        $this->reject(fn () => $this->service->create($this->actor, $this->payload($order, '4'), $submission));
        $other = $this->order();
        $this->reject(fn () => $this->service->update($this->actor, $delivery, $this->payload($other), $this->service->revision($delivery)));
        $payload['location_id'] = 999;
        $this->reject(fn () => $this->service->update($this->actor, $delivery, $payload, $this->service->revision($delivery)));
        $branch = Location::create(['name' => 'Other branch', 'type' => 'branch']);
        $this->reject(fn () => DB::table('sales_deliveries')->where('id', $delivery->id)->update(['location_id' => $branch->id]), QueryException::class);
    }

    private function scoped(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($user, $this->branch->id);
        $role = Role::create(['name' => 'delivery-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_delivery_only_permissions_and_cross_branch_access(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order);
        $user = $this->scoped([SalesPermissions::DELIVERIES_VIEW]);
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->reject(fn () => $this->service->create($user, $this->payload($order), (string) Str::uuid7()), HttpException::class);
        $this->reject(fn () => $this->service->update($user, $delivery, $this->payload($order), $this->service->revision($delivery)), HttpException::class);
        $this->reject(fn () => $this->service->cancel($user, $delivery, $this->service->revision($delivery)), HttpException::class);
        $this->assertTrue(SalesDeliveryResource::getEloquentQuery()->whereKey($delivery->id)->exists());
        $otherBranch = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $user->locations()->attach($otherBranch);
        app(LocationContext::class)->setFor($user, $otherBranch->id);
        $this->assertFalse(SalesDeliveryResource::getEloquentQuery()->whereKey($delivery->id)->exists());
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->assertNotFound();
        $this->assertSame([], $this->service->lineDetails($user, $order->id));
        $this->reject(fn () => $this->service->create($user, $this->payload($order), (string) Str::uuid7()), HttpException::class);
    }

    public function test_arabic_filament_create_edit_list_view_and_cancel(): void
    {
        $order = $this->order();
        app()->setLocale('ar');
        $before = $this->inventorySnapshot();
        Livewire::test(CreateSalesDelivery::class)->fillForm($this->payload($order))->call('create')->assertHasNoFormErrors();
        $delivery = SalesDelivery::firstOrFail();
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->assertSuccessful()->assertSee($delivery->delivery_number)->assertActionHidden('post');
        Livewire::test(EditSalesDelivery::class, ['record' => $delivery->uuid])->fillForm(['notes' => 'ملاحظات التسليم'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('ملاحظات التسليم', $delivery->fresh()->notes);
        Livewire::test(ListSalesDeliveries::class)->assertCanSeeTableRecords([$delivery])->filterTable('status', 'draft')->assertCanSeeTableRecords([$delivery]);
        Livewire::test(ViewSalesDelivery::class, ['record' => $delivery->uuid])->callAction('cancel')->assertHasNoActionErrors();
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame($before, $this->inventorySnapshot());
    }

    public function test_create_from_order_prefills_remaining_lines_and_delivery_permissions_are_registered(): void
    {
        $order = $this->order();
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])
            ->assertActionVisible('createDelivery');
        $this->get(SalesDeliveryResource::getUrl('create', ['sales_order' => $order->uuid]))->assertOk()->assertSee($order->order_number);
        foreach ([SalesPermissions::DELIVERIES_VIEW, SalesPermissions::DELIVERIES_CREATE, SalesPermissions::DELIVERIES_UPDATE, SalesPermissions::DELIVERIES_CANCEL] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'web']);
            $this->assertNotSame($permission, __($permission, [], 'ar'));
        }
        $this->assertContains('sales.deliveries.post', SalesPermissions::all());
        $this->assertDatabaseHas('permissions', ['name' => 'sales.deliveries.post']);
    }

    public function test_parent_status_and_partial_fulfillment_eligibility(): void
    {
        $order = $this->order();
        foreach (['draft', 'fulfilled', 'closed', 'cancelled'] as $status) {
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => $status]);
            $this->reject(fn () => $this->delivery($order));
        }
        DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'confirmed']);
        $posted = $this->delivery($order, '2');
        DB::table('sales_deliveries')->where('id', $posted->id)->update(['status' => 'posted', 'posted_at' => now()]);
        DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'partially_fulfilled', 'fulfillment_status' => 'partial']);
        $this->assertSame('8.0000', $this->delivery($order, '8')->items->first()->quantity);
        $this->reject(fn () => $this->delivery($order, '8.0001'));
    }

    public function test_ownership_migration_stops_before_ddl_when_historical_branches_conflict(): void
    {
        $delivery = $this->delivery($this->order());
        $branch = Location::create(['name' => 'Historical mismatch', 'type' => 'branch', 'is_active' => true]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            DB::table('sales_deliveries')->where('id', $delivery->id)->update(['location_id' => $branch->id]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
        $columns = Schema::getColumnListing('sales_deliveries');
        $migration = require app_path('Modules/Sales/Database/Migrations/2026_10_17_182133_add_draft_lifecycle_safety_to_sales_deliveries.php');
        $this->reject(fn () => $migration->up(), \LogicException::class);
        $this->assertSame($columns, Schema::getColumnListing('sales_deliveries'));
        $this->assertSame($branch->id, (int) DB::table('sales_deliveries')->where('id', $delivery->id)->value('location_id'));
        DB::table('sales_deliveries')->where('id', $delivery->id)->update(['location_id' => $this->branch->id]);
    }

    public function test_all_active_drafts_must_be_cancelled_and_stale_cancellation_is_rejected(): void
    {
        $order = $this->order();
        $first = $this->delivery($order);
        $second = $this->delivery($order);
        $stale = $this->service->revision($first);
        $first = $this->service->update($this->actor, $first, $this->payload($order, '2'), $stale);
        $this->reject(fn () => $this->service->cancel($this->actor, $first, $stale));
        $this->service->cancel($this->actor, $first, $this->service->revision($first));
        $this->assertTrue($order->hasDownstreamDocuments());
        $this->service->cancel($this->actor, $second, $this->service->revision($second));
        $this->assertFalse($order->hasDownstreamDocuments());
    }

    public function test_cancelled_deliveries_do_not_weaken_financial_document_protection(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order);
        $this->service->cancel($this->actor, $delivery, $this->service->revision($delivery));
        $this->assertFalse($order->hasDownstreamDocuments());
        SalesInvoice::factory()->create(['sales_order_id' => $order->id, 'location_id' => $order->location_id]);
        $before = $this->inventorySnapshot();
        $this->assertTrue($order->hasDownstreamDocuments());
        $this->reject(fn () => app(SalesOrderConfirmationService::class)->cancel($this->actor, $order));
        $this->assertSame($before, $this->inventorySnapshot());
    }

    public function test_delivery_roles_work_without_order_permissions_and_inactive_modules_or_branches_are_denied(): void
    {
        $order = $this->order();
        $user = $this->scoped([SalesPermissions::DELIVERIES_VIEW, SalesPermissions::DELIVERIES_CREATE,
            SalesPermissions::DELIVERIES_UPDATE, SalesPermissions::DELIVERIES_CANCEL]);
        $delivery = $this->service->create($user, $this->payload($order), (string) Str::uuid7());
        $delivery = $this->service->update($user, $delivery, $this->payload($order, '2'), $this->service->revision($delivery));
        $this->assertSame('cancelled', $this->service->cancel($user, $delivery, $this->service->revision($delivery))->status);
        $this->branch->update(['is_active' => false]);
        $this->reject(fn () => $this->service->create($this->actor, $this->payload($order), (string) Str::uuid7()), HttpException::class);
        $this->branch->update(['is_active' => true]);
        Module::where('code', 'sales')->update(['is_active' => false]);
        $this->reject(fn () => $this->service->create($this->actor, $this->payload($order), (string) Str::uuid7()), HttpException::class);
    }

    public function test_late_line_identity_failure_rolls_back_earlier_edits(): void
    {
        $order = $this->order(['4', '6']);
        $items = $order->items()->orderBy('id')->get();
        $payload = ['sales_order_id' => $order->id, 'items' => [
            ['sales_order_item_id' => $items[0]->id, 'quantity' => '3'],
            ['sales_order_item_id' => $items[1]->id, 'quantity' => '5']]];
        $delivery = $this->service->create($this->actor, $payload, (string) Str::uuid7());
        $revision = $this->service->revision($delivery);
        $rows = $delivery->items()->orderBy('id')->get(['id', 'sales_order_item_id', 'quantity'])->toArray();
        $rows[0]['quantity'] = '1';
        $rows[1]['id'] = 999999;
        $this->reject(fn () => $this->service->update($this->actor, $delivery, ['items' => $rows], $revision));
        $this->assertSame($revision, $this->service->revision($delivery->fresh()));
    }

    public function test_historical_overdelivery_fails_instead_of_clamping_remaining_quantity(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order, '10');
        DB::table('sales_deliveries')->where('id', $delivery->id)->update(['status' => 'posted']);
        DB::table('sales_delivery_items')->where('sales_delivery_id', $delivery->id)->update(['quantity' => '10.0001']);
        $this->reject(fn () => app(SalesFulfillmentService::class)->quantities($order));
        $this->reject(fn () => $this->delivery($order, '1'));
    }

    public function test_concurrent_edits_accept_one_revision_and_reject_stale_writer(): void
    {
        $order = $this->order();
        $delivery = $this->delivery($order);
        $before = $this->inventorySnapshot();
        $configuration = config('database.connections.mysql');
        $processes = [];
        $start = microtime(true) + 2;
        try {
            foreach (['4', '5'] as $quantity) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/SalesOrderWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'SALES_ACTOR' => (string) $this->actor->id, 'SALES_START' => (string) $start,
                    'SALES_OPERATION' => 'delivery-update', 'SALES_DELIVERY' => (string) $delivery->id,
                    'SALES_PAYLOAD' => json_encode($this->payload($order, $quantity), JSON_THROW_ON_ERROR),
                    'SALES_REVISION' => $this->service->revision($delivery), 'SALES_ALLOW_REJECTION' => '1',
                ]);
                $process->setTimeout(40)->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertCount(1, array_filter($results, fn ($result): bool => isset($result['rejected'])));
            $this->assertContains($delivery->items()->first()->quantity, ['4.0000', '5.0000']);
            $this->assertSame($before, $this->inventorySnapshot());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
