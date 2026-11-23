<?php

namespace Tests\Feature;

use App\Filament\RequestDisplayData;
use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerAllocations;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\EditVendorBill;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Schemas\VendorBillForm;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\VendorBillService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Treasury\Models\TreasuryAccount;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchasingSalesUiQueryTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private bool $profiling = false;

    /** @var list<array<string, mixed>> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'sales', 'products', 'inventory', 'inventory_costing', 'payments', 'treasury'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        session(['locale' => 'en']);
        DB::listen(function (QueryExecuted $query): void {
            if (! $this->profiling) {
                return;
            }
            $sources = [];
            $loading = null;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = str_replace('\\', '/', $frame['file'] ?? '');
                if (str_starts_with($file, str_replace('\\', '/', app_path()).'/')) {
                    $sources[] = substr($file, strlen(str_replace('\\', '/', base_path())) + 1).':'.$frame['line'].' '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
                }
                if (($frame['function'] ?? '') === 'getRelationshipFromMethod') {
                    $loading = 'lazy';
                } elseif (($frame['function'] ?? '') === 'eagerLoadRelation' && $loading === null) {
                    $loading = 'eager';
                }
            }
            $this->queries[] = ['sql' => $query->sql, 'bindings' => preg_match('/password|token|secret/i', $query->sql) ? ['[redacted]'] : $query->bindings,
                'database_ms' => $query->time, 'sources' => $sources, 'loading' => $loading];
        });
    }

    /** @return array<string, array{int}> */
    public static function documentSizes(): array
    {
        return ['one' => [1], 'ten' => [10], 'twenty' => [20], 'fifty' => [50], 'hundred' => [100]];
    }

    /** @return array{mixed, array<string, mixed>} */
    private function measure(string $page, string $operation, string $actor, int $lines, callable $callback): array
    {
        app('livewire')->flushState();
        $this->queries = [];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->profiling = true;
        $start = hrtime(true);
        try {
            $result = $callback();
        } finally {
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $this->profiling = false;
            $log = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(count($log), $this->queries);
        if (str_ends_with($page, 'ViewSalesCreditNote')) {
            $this->assertLessThanOrEqual(1, count(array_filter($log, fn (array $query): bool => preg_match('/^select \* from `payment_refunds` where (?:`payment_refunds`\.)?`sales_credit_note_id`/', $query['query']) === 1)),
                'Rendering the refund fields must load the refund list once, including when it is empty.');
        }
        $this->assertSame([], array_values(array_filter($log, fn (array $query): bool => ! preg_match('/^select /i', $query['query']))), 'Rendering and unsaved updates must not write business data.');
        if (str_ends_with($page, 'EditVendorBill') || str_ends_with($page, 'ViewSalesOrder') || str_ends_with($page, 'ViewPurchaseOrder')) {
            $this->assertSame([], array_values(array_filter($log, fn (array $query): bool => str_contains(strtolower($query['query']), 'for update'))), 'The optimized display callbacks must not acquire row locks.');
        }
        $groups = [];
        foreach ($this->queries as $index => $query) {
            $key = json_encode([$query['sql'], $query['bindings']], JSON_THROW_ON_ERROR);
            $groups[$key] ??= $query + ['executions' => []];
            $groups[$key]['executions'][] = $index + 1;
        }
        $metrics = ['phase' => getenv('UI_QUERY_PHASE') ?: 'baseline', 'engine' => getenv('STOCK_CONSTRAINT_TEST_ENGINE'),
            'page' => $page, 'operation' => $operation, 'actor' => $actor, 'lines' => $lines, 'queries' => count($log),
            'patterns' => count(array_unique(array_map(fn (array $query): string => preg_replace('/\b\d+(?:,\s*\d+)*\b/', '#', $query['sql']), $this->queries))),
            'duplicates' => count($log) - count($groups), 'database_ms' => array_sum(array_column($log, 'time')), 'request_ms' => $elapsed,
            'lazy_queries' => count(array_filter($this->queries, fn (array $query): bool => $query['loading'] === 'lazy')),
            'eager_queries' => count(array_filter($this->queries, fn (array $query): bool => $query['loading'] === 'eager')),
            'authorization_queries' => count(array_filter($this->queries, fn (array $query): bool => (bool) preg_match('/`(?:users|roles|permissions|model_has_roles|model_has_permissions|role_has_permissions|modules|modules_permissions|location_user)`|information_schema\./', $query['sql'])))];
        fwrite(STDOUT, 'UI_QUERY '.json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
        if (getenv('UI_QUERY_PHASE') === 'order-verified' && (str_ends_with($page, 'EditSalesOrder') || str_ends_with($page, 'EditPurchaseOrder'))) {
            $this->assertLessThanOrEqual(100, count($log), 'Order editor reads must remain bounded at every repeater size.');
            $this->assertSame(0, $metrics['lazy_queries']);
        }
        if (getenv('UI_QUERY_PHASE') === 'optimized') {
            if (str_ends_with($page, 'EditVendorBill')) {
                $this->assertLessThanOrEqual(80, count($log), 'The editor must batch rendering reads regardless of line count.');
                $this->assertSame(0, $metrics['lazy_queries']);
            }
            if (str_ends_with($page, 'ViewSalesOrder')) {
                $this->assertLessThanOrEqual(60, count($log), 'Reservation callbacks must reuse the same request read.');
            }
        }
        if ($path = getenv('UI_QUERY_OUTPUT')) {
            $this->assertSame(realpath(storage_path('framework')), realpath(dirname($path)));
            file_put_contents($path, json_encode($metrics + ['groups' => array_values($groups)], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        return [$result, $metrics];
    }

    #[DataProvider('documentSizes')]
    public function test_authenticated_purchasing_pages_and_livewire_updates(int $lines): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        $branch = Location::create(['name' => 'UI audit branch', 'type' => 'branch', 'is_active' => true]);
        $supplier = Supplier::factory()->create();
        $category = Category::factory()->create();
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'avco']);
        $variants = collect(range(1, $lines))->map(fn (): ProductVariant => ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['category_id' => $category->id, 'is_active' => true, 'status' => 'active'])->id,
            'color_id' => Color::factory()->create()->id, 'size_id' => Size::factory()->create()->id,
        ]));
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $bills = app(VendorBillService::class);
        $order = $orders->create($admin, ['supplier_id' => $supplier->id, 'location_id' => $branch->id, 'order_date' => today()->toDateString(),
            'items' => $variants->map(fn (ProductVariant $variant): array => ['variant_id' => $variant->id, 'quantity' => '3.3333', 'unit_price' => '10.123456'])->all()], (string) Str::uuid7());
        $order = $orders->confirm($admin, $order, $orders->revision($order));
        $receipt = $order->receipts()->where('status', 'draft')->firstOrFail();
        $receipt = $receipts->update($admin, $receipt, ['items' => $receipt->items()->orderBy('id')->get()->map(fn ($item): array => [
            'id' => $item->id, 'quantity' => '2.3333', 'unit_cost' => '10.123456'])->all()], $receipts->revision($receipt));
        $receipt = $receipts->post($admin, $receipt, $receipts->revision($receipt));
        $data = $bills->prefill($admin, $order, $receipt) + ['bill_number' => 'UI-POSTED'];
        foreach ($data['items'] as &$item) {
            $item['quantity'] = '0.3333';
            $item['allocations'][0]['quantity'] = '0.3333';
        }
        unset($item);
        $posted = $bills->create($admin, $data);
        $posted = $bills->post($admin, $posted, $bills->revision($posted));
        $data['bill_number'] = 'UI-DRAFT';
        $draft = $bills->create($admin, $data);
        $reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $reader->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $role = Role::create(['name' => 'UI audit branch operator', 'guard_name' => 'web', 'location_id' => $branch->id]);
        $role->givePermissionTo(Permission::where('guard_name', 'web')->where(fn ($query) => $query->where('name', 'like', 'purchasing.%')->orWhere('name', 'like', 'sales.%')->orWhere('name', 'like', 'payments.%')->orWhere('name', 'like', 'treasury.%'))->where('name', 'not like', '%view_all_locations')->get());
        $reader->assignRole($role);
        $records = ['PurchaseOrders' => $order, 'PurchaseReceipts' => $receipt, 'VendorBills' => $posted, 'Suppliers' => $supplier];
        foreach (['admin' => $admin, 'restricted' => $reader] as $actorType => $actor) {
            session()->forget('password_hash_web');
            $this->actingAs($actor);
            app(LocationContext::class)->setFor($actor, $actorType === 'admin' ? null : $branch->id);
            foreach ($records as $area => $record) {
                $singular = ['PurchaseOrders' => 'PurchaseOrder', 'PurchaseReceipts' => 'PurchaseReceipt', 'VendorBills' => 'VendorBill', 'Suppliers' => 'Supplier'][$area];
                $resource = 'App\\Modules\\Purchasing\\Filament\\Resources\\'.$area.'\\'.$singular.'Resource';
                $component = 'App\\Modules\\Purchasing\\Filament\\Resources\\'.$area.'\\Pages\\View'.$singular;
                $this->profilePage($component, $resource::getUrl('view', ['record' => $record]), $actorType, $lines);
            }
            $edit = EditVendorBill::class;
            $resource = VendorBillResource::class;
            $lastSnapshot = $this->profilePage($edit, $resource::getUrl('edit', ['record' => $draft]), $actorType, $lines, true);
            $this->assertSame($draft->total_amount, $draft->fresh()->total_amount);
        }
        session()->forget('password_hash_web');
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        $this->assertSame('2.0000', $bills->remaining($receipt->items()->firstOrFail()));
        if ($lines === 1) {
            session()->forget('password_hash_web');
            $this->actingAs($reader);
            app(LocationContext::class)->setFor($reader, $branch->id);
            RequestAuthorizationFacts::duringRead(function () use ($order, $role): void {
                $this->assertNotNull(VendorBillForm::authorizedOrder($order->uuid));
                $role->revokePermissionTo(PurchasingPermissions::ORDERS_VIEW);
                $this->assertNull(VendorBillForm::authorizedOrder($order->uuid));
                $role->givePermissionTo(PurchasingPermissions::ORDERS_VIEW);
                $this->assertNotNull(VendorBillForm::authorizedOrder($order->uuid));
            });
            $role->revokePermissionTo(PurchasingPermissions::BILLS_VIEW);
            app('livewire')->flushState();
            $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
                'snapshot' => $lastSnapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]]], ['X-Livewire' => 'true'])->assertNotFound();
            $role->givePermissionTo(PurchasingPermissions::BILLS_VIEW);
            $foreign = Location::create(['name' => 'Foreign UI audit branch', 'type' => 'branch', 'is_active' => true]);
            $foreignOrder = PurchaseOrder::factory()->create(['location_id' => $foreign->id, 'supplier_id' => $supplier->id]);
            $this->get(PurchaseOrderResource::getUrl('view', ['record' => $foreignOrder]))->assertNotFound();
            session()->forget('password_hash_web');
            $this->actingAs($admin);
            app(LocationContext::class)->setFor($admin, null);
        }
        $this->profileSalesDetails($admin, $reader, $branch, $variants, $supplier, $lines);
    }

    private function profileSalesDetails(User $admin, User $reader, Location $branch, Collection $variants, Supplier $supplier, int $lines): void
    {
        $this->flushSession();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        foreach ($variants as $variant) {
            $this->configureSalesPricing($variant);
        }
        $orders = app(SalesOrderService::class);
        $deliveries = app(SalesDeliveryService::class);
        $invoices = app(SalesInvoiceService::class);
        $returns = app(SalesReturnService::class);
        $credits = app(SalesCreditNoteService::class);
        $order = $orders->create($admin, ['location_id' => $branch->id, 'customer_name' => 'UI audit customer',
            'items' => $variants->map(fn (ProductVariant $variant): array => [
                'product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => '2', 'unit_price' => '20.123456',
            ])->all()], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($admin, $order);
        $delivery = $deliveries->create($admin, ['sales_order_id' => $order->id,
            'items' => $order->items->map(fn ($item): array => ['sales_order_item_id' => $item->id, 'quantity' => '2'])->all(),
        ], (string) Str::uuid7());
        $delivery = app(SalesDeliveryPostingService::class)->post($admin, $delivery, $deliveries->revision($delivery));
        $deliveryLines = $delivery->items()->get();
        $invoice = $invoices->create($admin, ['sales_order_id' => $order->id, 'invoice_date' => today()->toDateString(),
            'items' => $deliveryLines->map(fn ($item): array => ['sales_delivery_item_id' => $item->id, 'quantity' => '1'])->all()], (string) Str::uuid7());
        $invoice = $invoices->post($admin, $invoice, $invoices->revision($invoice));
        $return = $returns->create($admin, ['sales_order_id' => $order->id, 'return_date' => today()->toDateString(),
            'items' => $deliveryLines->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => '0.3333', 'disposition' => 'restock',
            ])->all()], (string) Str::uuid7());
        app(LocationContext::class)->setFor($reader, $branch->id);
        $return = app(SalesReturnApprovalService::class)->approve($reader, $return, $returns->revision($return));
        app(LocationContext::class)->setFor($admin, null);
        $return = app(SalesReturnPostingService::class)->post($admin, $return, $returns->revision($return));
        $credit = $credits->create($admin, ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id,
            'credit_date' => today()->toDateString(), 'reason' => 'UI audit return', 'items' => $return->postedAllocations()->where('sales_invoice_id', $invoice->id)->get()->map(fn ($allocation): array => [
                'sales_return_posted_allocation_id' => $allocation->id, 'quantity' => $allocation->quantity,
            ])->all()], (string) Str::uuid7());
        $credit = $credits->post($admin, $credit, $credits->revision($credit));
        $customer = Customer::factory()->create(['location_id' => $branch->id, 'created_by' => $admin->id]);
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $admin->id]);
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        app(CustomerAccounts::class)->linkInvoice($admin, $customer, $invoice, [], (string) Str::uuid7());
        $payments = app(CustomerPayments::class);
        $payment = $payments->createDraft($admin, ['customer_id' => $customer->id, 'payment_method_id' => $method->id,
            'treasury_account_id' => $cash->id, 'amount' => '1.0001', 'payment_date' => today()->toDateString()], (string) Str::uuid7());
        $payment = $payments->post($admin, $payment);
        app(CustomerAllocations::class)->allocate($admin, $payment, $invoice, '0.3333', (string) Str::uuid7());
        $vendorPayment = VendorPayment::factory()->create(['supplier_id' => $supplier->id, 'location_id' => $branch->id, 'created_by' => $admin->id]);
        $records = [
            ['Sales', 'SalesOrders', 'SalesOrder', $order], ['Sales', 'SalesDeliveries', 'SalesDelivery', $delivery],
            ['Sales', 'SalesInvoices', 'SalesInvoice', $invoice], ['Sales', 'SalesReturns', 'SalesReturn', $return],
            ['Sales', 'SalesCreditNotes', 'SalesCreditNote', $credit], ['Payments', 'Customers', 'Customer', $customer],
            ['Payments', 'CustomerPayments', 'CustomerPayment', $payment], ['Purchasing', 'VendorPayments', 'VendorPayment', $vendorPayment],
        ];
        foreach (['admin' => $admin, 'restricted' => $reader] as $actorType => $actor) {
            session()->forget('password_hash_web');
            $this->actingAs($actor);
            app(LocationContext::class)->setFor($actor, $actorType === 'admin' ? null : $branch->id);
            foreach ($records as [$module, $area, $singular, $record]) {
                $resource = 'App\\Modules\\'.$module.'\\Filament\\Resources\\'.$area.'\\'.$singular.'Resource';
                $component = 'App\\Modules\\'.$module.'\\Filament\\Resources\\'.$area.'\\Pages\\View'.$singular;
                $this->profilePage($component, $resource::getUrl('view', ['record' => $record]), $actorType, $lines);
            }
        }
    }

    private function profilePage(string $component, string $url, string $actor, int $lines, bool $edit = false): string
    {
        [$response] = $this->measure($component, 'http.get', $actor, $lines, fn () => $this->get($url)->assertOk());
        if ($edit) {
            $response->assertDontSee(__('Invalid receipt allocation.'));
            $response->assertSee(__('Previously billed').': '.QuantityFormatter::format('0.3333'));
            $response->assertSee(__('Remaining billable').': '.QuantityFormatter::format('2.0000'));
            $this->assertSame($lines, substr_count(strip_tags($response->getContent()), __('Previously billed')));
        }
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = null;
        foreach ($matches[1] as $encoded) {
            $candidate = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            $decoded = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR);
            if (array_key_exists('record', $decoded['data'] ?? [])) {
                $snapshot = $candidate;
                break;
            }
        }
        $this->assertNotNull($snapshot, 'The authenticated page must provide a record component snapshot.');
        foreach ([false, true] as $repeat) {
            $updates = [];
            if ($edit) {
                $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
                $items = $decoded['data']['data'][0]['items'][0];
                $key = array_key_first($items);
                $updates['data.items.'.$key.'.unit_price'] = $repeat ? '10.123456' : '11.123456';
            }
            [$response] = $this->measure($component, ($edit ? 'http.livewire.update' : 'http.livewire.refresh').($repeat ? '.repeat' : ''), $actor, $lines,
                fn () => $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
                    'snapshot' => $snapshot, 'updates' => $updates,
                    'calls' => $edit ? [] : [['path' => '', 'method' => '$refresh', 'params' => []]],
                ]]], ['X-Livewire' => 'true'])->assertOk());
            $snapshot = $response->json('components.0.snapshot');
            $this->assertIsString($snapshot);
        }

        return $snapshot;
    }

    #[DataProvider('documentSizes')]
    public function test_order_editors_batch_repeater_metadata(int $lines): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $branch = Location::create(['name' => 'Order editor branch', 'type' => 'branch', 'is_active' => true]);
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, $branch->id);
        $supplier = Supplier::factory()->create();
        $variants = collect(range(1, $lines))->map(function (): ProductVariant {
            $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create([
                'is_active' => true, 'status' => 'active', 'has_fixed_selling_price' => true, 'sale_price' => '20',
            ])->id]);
            $this->configureSalesPricing($variant);

            return $variant;
        });
        foreach ($variants as $variant) {
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $branch->id,
                'quantity_on_hand' => '100', 'quantity_available' => '100', 'quantity_reserved' => '0']);
        }
        $purchase = app(PurchaseOrderService::class)->create($admin, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'order_date' => today()->toDateString(), 'items' => $variants->map(fn ($variant): array => [
                'variant_id' => $variant->id, 'quantity' => '2', 'unit_price' => '10',
            ])->all()], (string) Str::uuid7());
        $sales = app(SalesOrderService::class)->create($admin, ['location_id' => $branch->id,
            'items' => $variants->map(fn ($variant): array => ['product_id' => $variant->product_id,
                'variant_id' => $variant->id, 'quantity' => '2', 'unit_price' => '20',
            ])->all()], (string) Str::uuid7());
        $reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $reader->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $reader->givePermissionTo(Permission::where('guard_name', 'web')->where('name', 'not like', '%all_locations%')->pluck('name')->all());
        foreach (['admin' => $admin, 'restricted' => $reader] as $actorType => $actor) {
            $this->flushSession();
            $this->actingAs($actor);
            app(LocationContext::class)->setFor($actor, $branch->id);
            foreach ([['Purchasing', 'PurchaseOrders', 'PurchaseOrder', $purchase], ['Sales', 'SalesOrders', 'SalesOrder', $sales]] as [$module, $area, $singular, $record]) {
                $resource = 'App\\Modules\\'.$module.'\\Filament\\Resources\\'.$area.'\\'.$singular.'Resource';
                $component = 'App\\Modules\\'.$module.'\\Filament\\Resources\\'.$area.'\\Pages\\Edit'.$singular;
                $snapshot = $this->profilePage($component, $resource::getUrl('edit', ['record' => $record]), $actorType, $lines);
                $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
                $items = $decoded['data']['data'][0]['items'][0];
                $key = array_key_first($items);
                [$updated] = $this->measure($component, 'http.livewire.quantity-update', $actorType, $lines,
                    fn () => $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
                        'snapshot' => $snapshot, 'updates' => ['data.items.'.$key.'.quantity' => '1'], 'calls' => [],
                    ]]], ['X-Livewire' => 'true'])->assertOk());
                $partials = $updated->json('components.0.effects.partials');
                $this->assertNull($updated->json('components.0.effects.html'), 'Changing one line must not redraw every order line.');
                $this->assertIsArray($partials);
                foreach (['discount_amount', 'tax_amount', 'subtotal', 'line_total'] as $field) {
                    $this->assertArrayHasKey('schema-component::form.items.'.$key.'.'.$field, $partials);
                }
                $this->assertArrayHasKey('schema-component::form.order-summary', $partials);
                $expectedTotals = $module === 'Sales'
                    ? [1 => '20.73', 10 => '393.90', 20 => '808.54', 50 => '2,052.44', 100 => '4,125.61']
                    : [1 => '10.00', 10 => '190.00', 20 => '390.00', 50 => '990.00', 100 => '1,990.00'];
                $this->assertStringContainsString('EGP '.$expectedTotals[$lines], $partials['schema-component::form.order-summary']);
                $this->assertStringContainsString($module === 'Sales' ? '20.73' : '10.00',
                    $partials['schema-component::form.items.'.$key.'.line_total']);
                if ($module === 'Sales') {
                    $this->assertArrayHasKey('schema-component::form.items.'.$key.'.line-profitability', $partials);
                }
                [$priceUpdated] = $this->measure($component, 'http.livewire.unit-price-update', $actorType, $lines,
                    fn () => $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
                        'snapshot' => $snapshot, 'updates' => ['data.items.'.$key.'.unit_price' => '25'], 'calls' => [],
                    ]]], ['X-Livewire' => 'true'])->assertOk());
                $this->assertNull($priceUpdated->json('components.0.effects.html'));
                $pricePartials = $priceUpdated->json('components.0.effects.partials');
                $this->assertStringContainsString('EGP 50.00', $pricePartials['schema-component::form.items.'.$key.'.subtotal']);
                $this->assertStringContainsString($module === 'Sales' ? '51.83' : '50.00',
                    $pricePartials['schema-component::form.items.'.$key.'.line_total']);
                $this->assertArrayHasKey('schema-component::form.order-summary', $pricePartials);
                [$added] = $this->measure($component, 'http.livewire.repeater-add', $actorType, $lines,
                    fn () => $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
                        'snapshot' => $snapshot, 'updates' => [], 'calls' => [[
                            'path' => '', 'method' => 'mountAction', 'params' => ['add', [], ['schemaComponent' => 'form.items']],
                        ]],
                    ]]], ['X-Livewire' => 'true'])->assertOk());
                $addedSnapshot = $added->json('components.0.snapshot');
                $addedData = json_decode($addedSnapshot, true, flags: JSON_THROW_ON_ERROR)['data']['data'][0]['items'][0];
                $this->assertCount($lines + 1, $addedData);
                $newKey = array_key_last($addedData);
                [$deleted] = $this->measure($component, 'http.livewire.repeater-delete', $actorType, $lines,
                    fn () => $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
                        'snapshot' => $addedSnapshot, 'updates' => [], 'calls' => [[
                            'path' => '', 'method' => 'mountAction', 'params' => ['delete', ['item' => $newKey], ['schemaComponent' => 'form.items']],
                        ]],
                    ]]], ['X-Livewire' => 'true'])->assertOk());
                $deletedData = json_decode($deleted->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR)['data']['data'][0]['items'][0];
                $this->assertCount($lines, $deletedData);
                $this->assertSame($record->total_amount, $record->fresh()->total_amount, 'Unsaved arithmetic must not change persisted totals.');
            }
        }
    }

    public function test_display_reads_are_isolated_and_invalidated_at_request_write_team_actor_and_transaction_boundaries(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $supplier = Supplier::factory()->create(['name' => 'Original supplier']);
        $this->actingAs($first);
        $reads = 0;
        $read = function () use ($supplier, &$reads): string {
            $reads++;

            return DB::table('suppliers')->where('id', $supplier->id)->value('name');
        };
        $request = request();
        RequestAuthorizationFacts::begin($request);
        try {
            $this->assertSame('Original supplier', RequestDisplayData::remember('probe', $read));
            $this->assertSame('Original supplier', RequestDisplayData::remember('probe', $read));
            $this->assertSame(1, $reads);
            DB::table('suppliers')->where('id', $supplier->id)->update(['name' => 'Updated supplier']);
            $this->assertSame('Updated supplier', RequestDisplayData::remember('probe', $read));
            $this->assertSame(2, $reads);
            $this->actingAs($second);
            RequestDisplayData::remember('probe', $read);
            $this->assertSame(3, $reads);
            setPermissionsTeamId(123);
            RequestDisplayData::remember('probe', $read);
            $this->assertSame(4, $reads);
            DB::transaction(function () use ($read, &$reads): void {
                RequestDisplayData::remember('probe', $read);
                RequestDisplayData::remember('probe', $read);
                $this->assertSame(6, $reads, 'Nested transaction reads must bypass reuse.');
            });
            RequestDisplayData::remember('probe', $read);
            $this->assertSame(7, $reads);
        } finally {
            RequestAuthorizationFacts::end($request);
            setPermissionsTeamId(null);
        }
        RequestDisplayData::remember('probe', $read);
        RequestDisplayData::remember('probe', $read);
        $this->assertSame(9, $reads, 'Unscoped reads must not reuse values.');
        RequestAuthorizationFacts::begin($request);
        try {
            RequestDisplayData::remember('probe', $read);
            $this->assertSame(10, $reads, 'A new request boundary must read again.');
        } finally {
            RequestAuthorizationFacts::end($request);
        }
    }
}
