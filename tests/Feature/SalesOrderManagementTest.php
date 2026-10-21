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
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesProductSelection;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesOrderManagementTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesOrderService $service;

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
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'sales')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        $this->branch = Location::create(['name' => 'Sales branch', 'type' => 'branch', 'is_active' => true]);
        $product = Product::factory()->create(['status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sale_price' => '25.1234']);
        $this->configureSalesPricing($this->variant, '3.2927', '6.8096');
        $this->balance($this->variant, $this->branch, '100');
        $this->service = app(SalesOrderService::class);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'location_id' => $this->branch->id, 'customer_name' => 'Customer', 'customer_phone' => null,
            'ordered_at' => '2026-10-17 12:30:00', 'notes' => null,
            'items' => [['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'quantity' => '3.3333', 'unit_price' => '10.123456']],
        ], $changes);
    }

    private function create(array $changes = []): SalesOrder
    {
        return $this->service->create($this->actor, $this->payload($changes), (string) Str::uuid7());
    }

    private function balance(ProductVariant $variant, Location $branch, string $available, string $reserved = '0'): StockBalance
    {
        return StockBalance::updateOrCreate(['variant_id' => $variant->id, 'location_id' => $branch->id], [
            'quantity_on_hand' => (string) BigDecimal::of($available)->plus($reserved)->toScale(4),
            'quantity_reserved' => (string) BigDecimal::of($reserved)->toScale(4),
            'quantity_available' => (string) BigDecimal::of($available)->toScale(4),
        ]);
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught, $caught->getMessage());

            return;
        }
        $this->fail('Expected operation rejection.');
    }

    private function scoped(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($user, $this->branch->id);
        $role = Role::create(['name' => 'sales-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_draft_creation_recalculates_multiple_lines_and_never_changes_inventory(): void
    {
        $this->balance($this->variant, $this->branch, '5');
        $before = StockBalance::first()->getAttributes();
        $data = $this->payload();
        $data['items'][0]['subtotal'] = '999999';
        $data['items'][0]['line_total'] = '999999';
        $data['items'][] = array_replace($data['items'][0], ['quantity' => '0.0001', 'unit_price' => '0.500000', 'discount_amount' => '0', 'tax_amount' => '0']);
        $order = $this->service->create($this->actor, $data, (string) Str::uuid7());
        $this->assertSame('draft', $order->status);
        $this->assertSame('unfulfilled', $order->fulfillment_status);
        $this->assertSame($this->actor->id, $order->created_by);
        $this->assertCount(2, $order->items);
        $this->assertSame('33.7446', $order->subtotal);
        $this->assertSame('1.1111', $order->discount_amount);
        $this->assertSame('2.2222', $order->tax_amount);
        $this->assertSame('34.8557', $order->total_amount);
        $this->assertSame('0.0001', $order->items[1]->subtotal);
        $this->assertSame($before, StockBalance::first()->getAttributes());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, DB::table('inventory_cost_entries')->count());
        foreach (['sales_deliveries', 'sales_invoices', 'payments', 'sales_returns'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_editing_adds_updates_and_removes_lines_without_repricing_the_catalog(): void
    {
        $data = $this->payload();
        $data['items'][] = $data['items'][0];
        $order = $this->service->create($this->actor, $data, (string) Str::uuid7());
        $removed = $order->items[1]->id;
        $this->variant->update(['sale_price' => '999']);
        $data['items'] = $this->service->formItems($order);
        $data['items'][0]['quantity'] = '2';
        array_pop($data['items']);
        $data['items'][] = array_replace($data['items'][0], ['id' => null, 'quantity' => '1']);
        $updated = $this->service->update($this->actor, $order, $data, $this->service->revision($order));
        $this->assertCount(2, $updated->items);
        $this->assertSame('10.123456', $updated->items[0]->unit_price);
        $this->assertSame('999.0000', $this->variant->fresh()->sale_price);
        $this->assertSame($order->order_number, $updated->order_number);
        $this->assertDatabaseMissing('sales_order_items', ['id' => $removed]);
        $this->assertSame('31.3704', $updated->total_amount);
        $this->assertSame('30.3704', $updated->subtotal);
    }

    public function test_invalid_quantities_prices_discounts_and_protected_fields_are_atomic(): void
    {
        foreach ([['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '1.00001'], ['quantity' => '100000000000000'],
            ['unit_price' => '-1'], ['unit_price' => '1e3'], ['unit_price' => 1.2], ['unit_price' => '0.1234567'],
            ['discount_amount' => '999'], ['discount_amount' => '-1'], ['tax_amount' => '-1'],
            ['variant_id' => 99999999], ['product_id' => 99999999], ['id' => 123]] as $invalid) {
            $data = $this->payload();
            $data['items'][] = array_replace($data['items'][0], $invalid);
            $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        }
        foreach (['status', 'fulfillment_status', 'created_by', 'order_number', 'total_amount', 'subtotal', 'tax_amount', 'discount_amount', 'channel', 'uuid'] as $field) {
            $this->reject(fn () => $this->create([$field => 'forged']));
        }
        $this->reject(fn () => $this->create(['items' => []]));
        $this->assertSame(0, SalesOrder::count());
        $this->assertSame(0, SalesOrderItem::count());
    }

    public function test_unallocated_and_inactive_variants_are_rejected_but_null_size_is_valid(): void
    {
        $this->assertNull($this->variant->size_id);
        $this->assertNotNull($this->create()->id);
        $ordinary = $this->variant;
        $this->variant = ProductVariant::factory()->make(['product_id' => $ordinary->product_id]);
        $this->variant->forceFill(['unallocated_product_id' => $ordinary->product_id])->save();
        $this->assertTrue($this->variant->isUnallocated());
        $this->reject(fn () => $this->create());
        $this->assertFalse(app(SalesProductSelection::class)->variants($this->actor, $this->branch->id)->whereKey($this->variant->id)->exists());
        $this->variant = $ordinary;
        $this->variant->update(['is_active' => false]);
        $this->reject(fn () => $this->create());
        $this->variant->update(['is_active' => true]);
        $this->variant->product->update(['status' => 'archived']);
        $this->reject(fn () => $this->create());
        $this->variant->product->update(['status' => 'active', 'is_active' => false]);
        $this->reject(fn () => $this->create());
    }

    public function test_branch_permissions_list_scope_and_direct_urls_are_enforced(): void
    {
        $own = $this->create();
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->balance($this->variant, $other, '20');
        $foreign = $this->create(['location_id' => $other->id]);
        $user = $this->scoped(SalesPermissions::all());
        $this->assertSame([$own->id], app(SalesAccess::class)->scopeOrders(SalesOrder::query(), $user)->pluck('id')->all());
        $this->assertFalse(Gate::forUser($user)->allows('view', $foreign));
        $this->reject(fn () => $this->service->create($user, $this->payload(['location_id' => $other->id]), (string) Str::uuid7()), HttpException::class);
        $this->reject(fn () => $this->service->update($user, $foreign, $this->payload(['location_id' => $other->id]), $this->service->revision($foreign)), HttpException::class);
        $this->get(SalesOrderResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
        $this->get(SalesOrderResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
        app(LocationContext::class)->setFor($user, $this->branch->id);
        Livewire::test(ListSalesOrders::class)->assertSuccessful()->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);
        Livewire::test(ViewSalesOrder::class, ['record' => $own->uuid])->assertSuccessful();
        $viewer = $this->scoped([SalesPermissions::VIEW]);
        $this->assertTrue($viewer->canAccessPanel(Filament::getPanel('admin')));
        $this->reject(fn () => $this->service->create($viewer, $this->payload(), (string) Str::uuid7()), HttpException::class);
        $this->reject(fn () => $this->service->update($viewer, $own, $this->payload(), $this->service->revision($own)), HttpException::class);
        $this->get(SalesOrderResource::getUrl('create'))->assertForbidden();
        $this->get(SalesOrderResource::getUrl('edit', ['record' => $own]))->assertForbidden();
    }

    public function test_warehouses_inactive_branches_and_disabled_module_are_denied_even_for_super_admin(): void
    {
        $warehouse = Location::create(['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->reject(fn () => $this->create(['location_id' => $warehouse->id]), HttpException::class);
        $this->branch->update(['is_active' => false]);
        $this->reject(fn () => $this->create(), HttpException::class);
        $this->branch->update(['is_active' => true]);
        Module::where('code', 'sales')->update(['is_active' => false]);
        $this->reject(fn () => $this->create(), HttpException::class);
    }

    public function test_non_drafts_stale_edits_and_foreign_line_identities_are_rejected(): void
    {
        $order = $this->create();
        $revision = $this->service->revision($order);
        $this->service->update($this->actor, $order, $this->payload(['notes' => 'Changed']), $revision);
        $this->reject(fn () => $this->service->update($this->actor, $order, $this->payload(), $revision));
        $foreign = $this->create();
        $data = $this->payload();
        $data['items'][0]['id'] = $foreign->items[0]->id;
        $this->reject(fn () => $this->service->update($this->actor, $order, $data, $this->service->revision($order->fresh())));
        foreach (['confirmed', 'partially_fulfilled', 'fulfilled', 'closed', 'cancelled'] as $status) {
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => $status]);
            $order = $order->fresh();
            $this->reject(fn () => $this->service->update($this->actor, $order, $this->payload(), $this->service->revision($order)));
            $this->assertFalse(Gate::allows('update', $order));
        }
    }

    public function test_stale_edit_revision_is_reported_as_a_visible_arabic_danger_notification(): void
    {
        $order = $this->create();
        app()->setLocale('ar');
        session(['locale' => 'ar']);
        $page = Livewire::test(EditSalesOrder::class, ['record' => $order->uuid]);
        $order->update(['notes' => 'Changed after opening the editor']);
        $page->call('save')->assertHasFormErrors(['revision'])
            ->assertNotified(Notification::make()->title(__('Sales operation failed'))
                ->body(__('This sales order changed. Reload before continuing.'))->danger());
        $this->assertSame('Changed after opening the editor', $order->fresh()->notes);
        $this->assertSame('0.0000', StockBalance::first()->quantity_reserved);
    }

    public function test_required_sales_form_errors_show_an_arabic_danger_notification(): void
    {
        app()->setLocale('ar');
        session(['locale' => 'ar']);
        $page = Livewire::test(CreateSalesOrder::class)->call('create')->assertHasFormErrors();
        $page->assertNotified(Notification::make()->title(__('Sales operation failed'))
            ->body(implode(' ', $page->instance()->getErrorBag()->all()))->danger());
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_creation_retry_reuses_document_and_conflicting_retry_is_rejected(): void
    {
        $token = (string) Str::uuid7();
        $order = $this->service->create($this->actor, $this->payload(), $token);
        $this->assertTrue($order->is($this->service->create($this->actor, $this->payload(), $token)));
        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(1, SalesOrderItem::count());
        $this->reject(fn () => $this->service->create($this->actor, $this->payload(['notes' => 'different']), $token));
    }

    public function test_filament_create_edit_view_and_arabic_rendering(): void
    {
        app()->setLocale('ar');
        Livewire::test(CreateSalesOrder::class)->fillForm($this->payload())
            ->assertSee('fi-fo-table-repeater', false)->assertSee(__('Discount'))->assertSee(__('Taxes'))
            ->call('create')->assertHasNoFormErrors();
        $order = SalesOrder::firstOrFail();
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertSuccessful()->assertSee($order->order_number)
            ->assertSee('data-commercial-snapshot="discount"', false)->assertSee('data-commercial-snapshot="tax"', false);
        Livewire::test(EditSalesOrder::class, ['record' => $order->uuid])->fillForm(['customer_name' => 'Updated customer'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Updated customer', $order->fresh()->customer_name);
        Livewire::test(ListSalesOrders::class)->assertCanSeeTableRecords([$order])->filterTable('status', 'draft')->assertCanSeeTableRecords([$order]);
        if (getenv('EXPORT_CATALOG_BROWSER_FIXTURE') === '1') {
            Module::where('code', 'purchasing')->update(['is_active' => true]);
            app(PurchaseOrderService::class)->create($this->actor, [
                'supplier_id' => Supplier::factory()->create()->id,
                'location_id' => $this->branch->id,
                'items' => [['variant_id' => $this->variant->id, 'quantity' => '3', 'unit_price' => '10.123456']],
            ], (string) Str::uuid7());
            $database = DB::connection()->getDatabaseName();
            $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $database);
            $process = new Process(['C:/xampp/mysql/bin/mysqldump.exe', '--host=127.0.0.1', '--port=3338', '--user=root',
                '--result-file=D:/PointSale/output/catalog-browser-fixture.sql', $database]);
            $process->mustRun();
        }
    }

    public function test_deactivated_draft_discount_can_be_reviewed_and_removed_in_the_commercial_table(): void
    {
        $order = $this->create();
        $this->variant->product->discounts()->update(['is_active' => false]);
        $component = Livewire::test(EditSalesOrder::class, ['record' => $order->uuid]);
        $key = array_key_first($component->get('data.items'));
        $component->set('data.items.'.$key.'.discount_id', null)->call('save')->assertHasNoFormErrors();
        $this->assertSame('0.0000', $order->fresh()->discount_amount);
        $this->assertNull($order->fresh()->items->first()->commercial_snapshot['discount']);
    }

    public function test_concurrent_number_generation_and_duplicate_submissions(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent posting requires the isolated MariaDB runner.');
        }
        $configuration = config('database.connections.mysql');
        $database = DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $database);
        $token = (string) Str::uuid7();
        $tokens = [$token, $token, (string) Str::uuid7(), (string) Str::uuid7()];
        $processes = [];
        $start = microtime(true) + 2;
        try {
            foreach ($tokens as $submission) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/SalesOrderWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'SALES_ACTOR' => (string) $this->actor->id, 'SALES_START' => (string) $start,
                    'SALES_SUBMISSION' => $submission, 'SALES_PAYLOAD' => json_encode($this->payload(), JSON_THROW_ON_ERROR),
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
            $this->assertSame($results[0], $results[1]);
            $this->assertCount(3, array_unique(array_column($results, 'number')));
            $this->assertSame(3, SalesOrder::count());
            $this->assertSame(3, SalesOrderItem::count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_product_and_variant_options_only_include_positive_stock_at_the_selected_branch(): void
    {
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $warehouse = Location::create(['name' => 'Central warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $otherProduct = Product::factory()->create(['status' => 'active', 'is_active' => true]);
        $otherVariant = ProductVariant::factory()->create(['product_id' => $otherProduct->id]);
        $this->balance($otherVariant, $other, '50');
        $this->balance($otherVariant, $warehouse, '500');
        $zero = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $this->balance($zero, $this->branch, '0', '20');
        $missing = ProductVariant::factory()->create(['product_id' => $otherProduct->id]);
        $size = Size::factory()->create(['name' => '42']);
        $color = Color::factory()->create(['name' => 'Blue']);
        $sized = ProductVariant::factory()->create(['product_id' => $this->variant->product_id, 'size_id' => $size->id, 'color_id' => $color->id]);
        $this->balance($sized, $this->branch, '2.1250', '3');
        $inactive = ProductVariant::factory()->create(['product_id' => $this->variant->product_id, 'is_active' => false]);
        $this->balance($inactive, $this->branch, '10');
        $unallocated = ProductVariant::factory()->make(['product_id' => $this->variant->product_id]);
        $unallocated->forceFill(['unallocated_product_id' => $this->variant->product_id])->save();
        $this->balance($unallocated, $this->branch, '10');
        $selection = app(SalesProductSelection::class);
        $this->assertSame([$this->variant->product_id], array_keys($selection->productOptions($this->actor, $this->branch->id)));
        $this->assertEqualsCanonicalizing([$this->variant->id, $sized->id], $selection->variants($this->actor, $this->branch->id)->pluck('id')->all());
        $this->assertSame([$otherVariant->id], $selection->variants($this->actor, $other->id)->pluck('id')->all());
        $this->assertSame([], $selection->productOptions($this->actor, null));
        $this->assertSame([], $selection->productOptions($this->actor, $warehouse->id));
        $this->assertSame([], $selection->variantOptions($this->actor, $this->branch->id, $otherProduct->id));
        $options = $selection->variantOptions($this->actor, $this->branch->id, $this->variant->product_id);
        foreach ([$sized->sku, '42', 'Blue', '2.125'] as $labelPart) {
            $this->assertStringContainsString($labelPart, $options[$sized->id]);
        }
        $this->assertArrayNotHasKey($zero->id, $options);
        $this->assertArrayNotHasKey($missing->id, $options);
        $this->assertArrayNotHasKey($unallocated->id, $options);
        $this->variant->product->update(['is_active' => false]);
        $this->assertSame([], $selection->productOptions($this->actor, $this->branch->id));
    }

    public function test_missing_zero_and_other_branch_stock_cannot_satisfy_a_forged_order(): void
    {
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->balance($this->variant, $other, '1000');
        $this->balance($this->variant, $this->branch, '0', '50');
        $this->reject(fn () => $this->create());
        StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->delete();
        $data = $this->payload();
        $data['items'][0]['available_quantity'] = '999999';
        $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertSame(0, SalesOrder::count());
        $this->assertSame(1, StockBalance::count());
    }

    public function test_stock_validation_uses_available_quantity_and_exact_decimal_aggregate(): void
    {
        $balance = $this->balance($this->variant, $this->branch, '0.3000', '99');
        $before = $balance->getAttributes();
        $data = $this->payload();
        $data['items'][0] = array_replace($data['items'][0], ['quantity' => '0.1000', 'unit_price' => '1']);
        $data['items'][] = array_replace($data['items'][0], ['quantity' => '0.2000']);
        $order = $this->service->create($this->actor, $data, (string) Str::uuid7());
        $this->assertSame('0.3099', $order->total_amount);
        $this->assertSame($before, $balance->fresh()->getAttributes());
        $data['items'][1]['quantity'] = '0.2001';
        try {
            $this->service->create($this->actor, $data, (string) Str::uuid7());
            $this->fail('Aggregate shortage must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.quantity', $exception->errors());
            $this->assertArrayHasKey('items.1.quantity', $exception->errors());
        }
        $data['items'] = [array_replace($data['items'][0], ['quantity' => '0.30001'])];
        $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stock_shortage_has_an_arabic_product_branch_and_quantity_message(): void
    {
        app()->setLocale('ar');
        $this->balance($this->variant, $this->branch, '5', '20');
        $data = $this->payload();
        $data['items'][0]['quantity'] = '12';
        try {
            $this->service->create($this->actor, $data, (string) Str::uuid7());
            $this->fail('Shortage must be rejected.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['items.0.quantity'][0];
            foreach (['الكمية المطلوبة', $this->variant->product->name, $this->branch->name, '12', '5'] as $part) {
                $this->assertStringContainsString($part, $message);
            }
        }
    }

    public function test_selection_does_not_allow_saving_after_stock_changes(): void
    {
        $selection = app(SalesProductSelection::class);
        $this->assertArrayHasKey($this->variant->id, $selection->variantOptions($this->actor, $this->branch->id, $this->variant->product_id));
        $this->balance($this->variant, $this->branch, '1');
        $this->reject(fn () => $this->create());
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_understocked_drafts_remain_viewable_and_failed_edits_preserve_all_lines_and_prices(): void
    {
        $order = $this->create();
        $attributes = $order->getAttributes();
        $lines = $order->items->map->getAttributes()->all();
        $data = $this->payload();
        $data['items'] = $this->service->formItems($order);
        $data['items'][0]['quantity'] = '101';
        $this->reject(fn () => $this->service->update($this->actor, $order, $data, $this->service->revision($order)));
        $this->balance($this->variant, $this->branch, '0', '100');
        $data['items'][0]['quantity'] = '3.3333';
        $this->reject(fn () => $this->service->update($this->actor, $order, $data, $this->service->revision($order)));
        Livewire::test(ViewSalesOrder::class, ['record' => $order->uuid])->assertSuccessful()->assertSee($order->order_number);
        $edit = Livewire::test(EditSalesOrder::class, ['record' => $order->uuid])->assertSuccessful();
        $state = array_values($edit->get('data.items'));
        $this->assertSame($this->variant->id, (int) $state[0]['variant_id']);
        $this->assertSame('10.123456', $state[0]['unit_price']);
        $this->assertSame('0.0000', $state[0]['available_quantity']);
        $edit->call('save')->assertHasFormErrors(['items.'.$this->firstItemKey($edit).'.quantity']);
        $this->assertSame($attributes, $order->fresh()->getAttributes());
        $this->assertSame($lines, $order->items()->get()->map->getAttributes()->all());
        $this->assertSame(0, StockMovement::count());
    }

    private function firstItemKey(mixed $component): string
    {
        return (string) array_key_first($component->get('data.items'));
    }

    public function test_branch_and_product_changes_clear_stale_selections_and_show_available_quantity(): void
    {
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->balance($this->variant, $other, '2.1250');
        $component = Livewire::test(CreateSalesOrder::class)->fillForm($this->payload());
        $key = $this->firstItemKey($component);
        $component->set('data.items.'.$key.'.variant_id', $this->variant->id)
            ->assertSet('data.items.'.$key.'.available_quantity', '100.0000');
        $component->set('data.items.'.$key.'.product_id', null)
            ->assertSet('data.items.'.$key.'.variant_id', null)
            ->assertSet('data.items.'.$key.'.available_quantity', null)
            ->assertSet('data.items.'.$key.'.unit_price', '0');
        $component->fillForm($this->payload())->set('data.location_id', $other->id)->assertSet('data.items', []);
        $component->fillForm($this->payload(['location_id' => $other->id]));
        $key = $this->firstItemKey($component);
        $component->set('data.items.'.$key.'.variant_id', $this->variant->id)
            ->assertSet('data.items.'.$key.'.available_quantity', '2.1250')->assertSee('2.125');
    }

    public function test_unauthorized_branches_cannot_be_used_to_query_stock_options(): void
    {
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->balance($this->variant, $other, '999');
        $user = $this->scoped(SalesPermissions::all());
        $selection = app(SalesProductSelection::class);
        $this->assertSame([], $selection->productOptions($user, $other->id));
        $this->assertSame([], $selection->variantOptions($user, $other->id, $this->variant->product_id));
        $this->assertNotEmpty($selection->variantOptions($user, $this->branch->id, $this->variant->product_id));
    }

    public function test_variant_option_query_count_does_not_grow_per_variant(): void
    {
        $selection = app(SalesProductSelection::class);
        DB::enableQueryLog();
        $selection->variantOptions($this->actor, $this->branch->id, $this->variant->product_id);
        $initial = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 0; $index < 12; $index++) {
            $variant = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
            $this->balance($variant, $this->branch, '5');
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertCount(13, $selection->variantOptions($this->actor, $this->branch->id, $this->variant->product_id));
        $this->assertSame($initial, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_multiple_drafts_do_not_reserve_or_consume_available_stock(): void
    {
        $before = StockBalance::first()->getAttributes();
        $this->create();
        $this->create();
        $this->assertSame(2, SalesOrder::count());
        $this->assertSame($before, StockBalance::first()->getAttributes());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_large_quantities_are_compared_without_losing_four_decimal_precision(): void
    {
        $this->balance($this->variant, $this->branch, '90071992547409.9999');
        $data = $this->payload();
        $data['items'][0] = array_replace($data['items'][0], ['quantity' => '90071992547409.9999',
            'unit_price' => '0', 'discount_amount' => '0', 'tax_amount' => '0']);
        $this->service->create($this->actor, $data, (string) Str::uuid7());
        $data['items'][0]['quantity'] = '90071992547410.0000';
        $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_stock_balance_locks_follow_inventory_variant_order_and_duplicates_lock_once(): void
    {
        $second = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $this->balance($second, $this->branch, '100');
        $data = $this->payload();
        $data['items'] = [array_replace($data['items'][0], ['variant_id' => $second->id]), $data['items'][0], $data['items'][0]];
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'stock_balances') && str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = $query->bindings;
            }
        });
        $this->service->create($this->actor, $data, (string) Str::uuid7());
        $this->assertSame([[$this->variant->id, $this->branch->id], [$second->id, $this->branch->id]], $locks);
    }

    public function test_draft_waits_for_a_stock_writer_and_validates_the_latest_committed_balance(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Stock row locking requires the isolated MariaDB runner.');
        }
        $configuration = config('database.connections.mysql');
        $database = DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $database);
        config(['database.connections.sales_stock_writer' => $configuration]);
        $writer = DB::connection('sales_stock_writer');
        $this->assertSame($database, $writer->getDatabaseName());
        $process = null;
        try {
            $writer->beginTransaction();
            $writer->table('stock_balances')->where('variant_id', $this->variant->id)
                ->where('location_id', $this->branch->id)->lockForUpdate()->first();
            $process = new Process([PHP_BINARY, base_path('tests/Support/SalesOrderWorker.php')], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                'SALES_ACTOR' => (string) $this->actor->id, 'SALES_START' => '0',
                'SALES_SUBMISSION' => (string) Str::uuid7(), 'SALES_PAYLOAD' => json_encode($this->payload(), JSON_THROW_ON_ERROR),
                'SALES_ALLOW_REJECTION' => '1',
            ]);
            $process->setTimeout(30)->start();
            $waiting = false;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && $process->isRunning()) {
                foreach (DB::select('SHOW FULL PROCESSLIST') as $connection) {
                    $sql = strtolower($connection->Info ?? '');
                    if ($connection->db === $database && str_contains($sql, 'stock_balances') && str_contains($sql, 'for update')) {
                        $waiting = true;
                        break 2;
                    }
                }
                usleep(10000);
            }
            $this->assertTrue($waiting, 'Sales must wait on the branch stock balance: '.$process->getErrorOutput());
            $writer->table('stock_balances')->where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)
                ->update(['quantity_on_hand' => '1.0000', 'quantity_reserved' => '0.0000', 'quantity_available' => '1.0000']);
            $writer->commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('items.0.quantity', $result['rejected']);
            $this->assertSame(0, SalesOrder::count());
            $this->assertSame('1.0000', StockBalance::first()->quantity_available);
            $this->assertSame(0, StockMovement::count());
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            DB::purge('sales_stock_writer');
        }
    }
}
