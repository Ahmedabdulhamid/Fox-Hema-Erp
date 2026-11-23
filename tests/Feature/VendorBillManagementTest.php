<?php

namespace Tests\Feature;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\CreateVendorBill;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\EditVendorBill;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ListVendorBills;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ViewVendorBill;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\VendorBillPdfService;
use App\Modules\Purchasing\Services\VendorBillService;
use App\Modules\Taxes\Models\Tax;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\PurchasingPhase3TestSetup;
use Tests\TestCase;

class VendorBillManagementTest extends TestCase
{
    use PurchasingPhase3TestSetup;

    private User $actor;

    private Location $location;

    private Supplier $supplier;

    private ProductVariant $variant;

    private VendorBillService $bills;

    private PurchaseOrderService $orders;

    private PurchaseReceiptService $receipts;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        session(['locale' => 'en']);
        $this->setUpPhase3Database(true);
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'inventory', 'inventory_costing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->location = Location::create(['name' => 'Billing warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active',
                'category_id' => Category::factory()->create()->id])->id,
            'sale_price' => '250.0000', 'cost_price' => '99.0000',
        ]);
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'avco']);
        $this->bills = app(VendorBillService::class);
        $this->orders = app(PurchaseOrderService::class);
        $this->receipts = app(PurchaseReceiptService::class);
    }

    public function test_purchase_invoice_pdf_buttons_and_bilingual_downloads_preserve_saved_finances(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        $url = fn (string $locale): string => route('purchasing.vendor-bills.pdf', ['bill' => $bill->uuid, 'locale' => $locale]);
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->assertActionHidden('download_ar')->assertActionHidden('download_en');
        $this->get($url('en'))->assertForbidden();
        $bill = $this->postBill($bill);
        $billBefore = $bill->getAttributes();
        $itemsBefore = $bill->items()->get()->map->getAttributes()->all();
        $inventoryBefore = $this->inventory();
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->assertActionVisible('download_ar')->assertActionVisible('download_en');
        Livewire::test(ListVendorBills::class)->assertTableActionVisible('download_ar', $bill)->assertTableActionVisible('download_en', $bill);
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $prepared = app(VendorBillPdfService::class)->prepare($this->actor, $bill, $locale);
            $html = view('purchasing::vendor-bills.pdf', $prepared)->render();
            $this->assertStringContainsString('lang="'.$locale.'" dir="'.$direction.'"', $html);
            $this->assertStringContainsString(trans('Vendor Bill', [], $locale), $html);
            $this->assertStringContainsString($bill->bill_number, $html);
            $this->assertSame($bill->total_amount, $prepared['bill']->total_amount);
            $response = $this->get($url($locale))->assertOk()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('-'.strtoupper($locale).'.pdf', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame($billBefore, $bill->fresh()->getAttributes());
        $this->assertSame($itemsBefore, $bill->items()->get()->map->getAttributes()->all());
        $this->assertSame($inventoryBefore, $this->inventory());
        $this->get($url('fr'))->assertNotFound();
        $this->get('/purchasing/vendor-bills/'.$bill->id.'/pdf/en')->assertNotFound();
    }

    public function test_purchase_invoice_pdf_respects_view_permission_location_and_module_status(): void
    {
        $bill = $this->postBill($this->bills->create($this->actor, $this->data($this->receive($this->order(), '50'))));
        $url = route('purchasing.vendor-bills.pdf', ['bill' => $bill->uuid, 'locale' => 'en']);
        $viewer = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $viewer->locations()->attach($this->location);
        app(LocationContext::class)->setFor($viewer, $this->location->id);
        $role = Role::create(['name' => 'bill-pdf-viewer', 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo([PurchasingPermissions::BILLS_VIEW]);
        $viewer->assignRole($role);
        $this->actingAs($viewer);
        $this->get($url)->assertOk();
        $other = Location::create(['name' => 'Other PDF location', 'type' => 'warehouse', 'is_active' => true]);
        $viewer->locations()->attach($other);
        $otherRole = Role::create(['name' => 'other-bill-pdf-viewer', 'guard_name' => 'web', 'location_id' => $other->id]);
        $otherRole->givePermissionTo([PurchasingPermissions::BILLS_VIEW]);
        app(LocationContext::class)->setFor($viewer, $other->id);
        $viewer->assignRole($otherRole);
        $this->get($url)->assertNotFound();
        $unprivileged = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $unprivileged->locations()->attach($this->location);
        app(LocationContext::class)->setFor($unprivileged, $this->location->id);
        $this->actingAs($unprivileged)->get($url)->assertForbidden();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $this->get($url)->assertForbidden();
    }

    public function test_purchase_invoice_pdf_escapes_notes_and_uses_saved_line_descriptions_and_limits(): void
    {
        $data = $this->data($this->receive($this->order(), '50'));
        $data['notes'] = '<script>alert("unsafe")</script>';
        $data['items'][0]['description'] = 'Agreed historic product';
        $bill = $this->postBill($this->bills->create($this->actor, $data));
        $this->variant->product->update(['name' => 'New catalog description']);
        $prepared = app(VendorBillPdfService::class)->prepare($this->actor, $bill, 'en');
        $html = view('purchasing::vendor-bills.pdf', $prepared)->render();
        $this->assertStringContainsString('Agreed historic product', $html);
        $this->assertStringNotContainsString('New catalog description', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        config(['sales-invoices.pdf.max_lines' => 0]);
        $this->get(route('purchasing.vendor-bills.pdf', ['bill' => $bill->uuid, 'locale' => 'en']))->assertStatus(422);
    }

    public function test_unknown_size_product_order_receipt_and_vendor_bill_preserve_variant_and_value(): void
    {
        Module::where('code', 'products')->update(['is_active' => true]);
        $this->variant->update(['size_id' => Size::factory()->create()->id]);
        $order = $this->orders->create($this->actor, ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $this->variant->product_id,
                'receiving_mode' => 'unknown', 'quantity' => '50', 'unit_price' => '200']]], (string) Str::uuid7());
        $source = $order->items->first()->variant;
        $this->assertTrue($source->isUnallocated());
        $order = $this->orders->confirm($this->actor, $order, $this->orders->revision($order));
        $receipt = $this->receive($order, '50');
        $data = $this->bills->prefill($this->actor, $order, $receipt) + ['bill_number' => 'UNKNOWN-SIZES-1'];
        $this->assertSame($source->id, $data['items'][0]['variant_id']);
        $bill = $this->bills->create($this->actor, $data);
        $bill = $this->bills->post($this->actor, $bill, $this->bills->revision($bill));
        $this->assertSame('posted', $bill->status);
        $this->assertSame('10000.0000', $bill->total_amount);
        $this->assertSame($source->id, $bill->items->first()->variant_id);
        $this->assertSame(1, StockMovement::count());
    }

    private function order(string $quantity = '50'): PurchaseOrder
    {
        $order = $this->orders->create($this->actor, ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id,
            'order_date' => '2026-10-14', 'items' => [['variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => '190']]],
            (string) Str::uuid7());

        return $this->orders->confirm($this->actor, $order, $this->orders->revision($order));
    }

    private function receive(PurchaseOrder $order, string $quantity): PurchaseReceipt
    {
        $receipt = $order->receipts()->where('status', 'draft')->firstOrFail();
        $receipt = $this->receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items->first()->id, 'quantity' => $quantity, 'unit_cost' => '200']]], $this->receipts->revision($receipt));

        return $this->receipts->post($this->actor, $receipt, $this->receipts->revision($receipt));
    }

    private function data(PurchaseReceipt $receipt, string $quantity = '50', string $number = 'INV-1'): array
    {
        $data = $this->bills->prefill($this->actor, $receipt->purchaseOrder, $receipt);
        $data['bill_number'] = $number;
        $data['items'][0]['quantity'] = $quantity;
        $data['items'][0]['allocations'][0]['quantity'] = $quantity;

        return $data;
    }

    private function postBill(VendorBill $bill): VendorBill
    {
        return $this->bills->post($this->actor, $bill, $this->bills->revision($bill));
    }

    public static function queryDocumentSizes(): array
    {
        return ['small' => [1], 'ten' => [10], 'large' => [20], 'fifty' => [50], 'hundred' => [100], 'restricted-small' => [1, true]];
    }

    #[DataProvider('queryDocumentSizes')]
    public function test_bill_query_growth_preserves_receipt_usage_labels_and_revision_bytes(int $lineCount, bool $restricted = false): void
    {
        $variants = collect(range(1, $lineCount))->map(fn (int $index): ProductVariant => ProductVariant::factory()->create([
            'product_id' => $this->variant->product_id,
            'color_id' => Color::factory()->create()->id,
            'size_id' => Size::factory()->create()->id,
        ]));
        $order = $this->orders->create($this->actor, ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id,
            'order_date' => '2026-10-14', 'items' => $variants->map(fn (ProductVariant $variant): array => [
                'variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '190'])->all()], (string) Str::uuid7());
        $order = $this->orders->confirm($this->actor, $order, $this->orders->revision($order));
        $postedReceipts = [];
        foreach (['1', '2'] as $quantity) {
            $receipt = $order->receipts()->where('status', 'draft')->firstOrFail();
            $receipt = $this->receipts->update($this->actor, $receipt, ['items' => $receipt->items()->orderBy('id')->get()->map(fn ($item): array => [
                'id' => $item->id, 'quantity' => $quantity, 'unit_cost' => '200'])->all()], $this->receipts->revision($receipt));
            $postedReceipts[] = $this->receipts->post($this->actor, $receipt, $this->receipts->revision($receipt));
        }
        [$first, $second] = $postedReceipts;
        $data = $this->bills->prefill($this->actor, $order, $first) + ['bill_number' => 'QUERY-POSTED'];
        $secondLines = $second->items()->with('purchaseOrderItem')->get()->keyBy(fn ($line): int => $line->purchaseOrderItem->variant_id);
        foreach ($data['items'] as &$item) {
            $item['quantity'] = '0.4444';
            $item['allocations'][0]['quantity'] = '0.3333';
            $item['allocations'][] = ['purchase_receipt_item_id' => $secondLines[$item['variant_id']]->id, 'quantity' => '0.1111'];
        }
        unset($item);
        $posted = $this->postBill($this->bills->create($this->actor, $data));
        $data['bill_number'] = 'QUERY-DRAFT';
        $draft = $this->bills->create($this->actor, $data);
        $data['bill_number'] = 'QUERY-CANCELLED';
        $cancelled = $this->bills->create($this->actor, $data);
        $this->bills->cancel($this->actor, $cancelled, $this->bills->revision($cancelled));
        $before = $this->inventory();
        $reader = $this->actor;
        if ($restricted) {
            $reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
            $reader->locations()->attach($this->location);
            app(LocationContext::class)->setFor($reader, $this->location->id);
            $role = Role::create(['name' => 'prefill-reader', 'guard_name' => 'web', 'location_id' => $this->location->id]);
            $role->givePermissionTo([PurchasingPermissions::BILLS_VIEW, PurchasingPermissions::BILLS_CREATE, PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::RECEIPTS_VIEW]);
            $reader->assignRole($role);
            $this->actingAs($reader);
        }
        $tracing = false;
        $trace = [];
        DB::listen(function (QueryExecuted $query) use (&$tracing, &$trace): void {
            if (! $tracing) {
                return;
            }
            $frames = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = str_replace('\\', '/', $frame['file'] ?? '');
                if (str_starts_with($file, str_replace('\\', '/', app_path()).'/')) {
                    $frames[] = substr($file, strlen(str_replace('\\', '/', base_path())) + 1).':'.$frame['line'].' '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
                }
            }
            $trace[] = ['sql' => $query->sql, 'bindings' => preg_match('/password|token|secret/i', $query->sql) ? ['[redacted]'] : $query->bindings,
                'milliseconds' => $query->time, 'source' => $frames];
        });
        $measure = function (string $operation, callable $callback) use ($lineCount, $restricted, &$tracing, &$trace): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $tracing = $operation === 'bill_prefill' && $lineCount === 1;
            $memoryBefore = memory_get_usage();
            $started = hrtime(true);
            try {
                $result = $callback();
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                $memoryDelta = memory_get_usage() - $memoryBefore;
                $queries = DB::getQueryLog();
            } finally {
                $tracing = false;
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $patterns = collect($queries)->map(fn (array $query): string => preg_replace('/\b\d+(?:,\s*\d+)*\b/', '#', $query['query']))->unique()->count();
            $uniqueExecutions = collect($queries)->map(fn (array $query): string => json_encode([$query['query'], $query['bindings']], JSON_THROW_ON_ERROR))->unique()->count();
            if ($operation === 'bill_prefill') {
                fwrite(STDOUT, "\nPHASE2A1 ".json_encode(['actor' => $restricted ? 'restricted' : 'admin', 'lines' => $lineCount, 'queries' => count($queries), 'patterns' => $patterns,
                    'duplicates' => count($queries) - $uniqueExecutions, 'database_ms' => array_sum(array_column($queries, 'time')),
                    'service_ms' => $elapsed, 'memory_delta_bytes' => $memoryDelta, 'peak_bytes' => memory_get_peak_usage()], JSON_THROW_ON_ERROR)."\n");
                if ($lineCount === 1) {
                    $this->assertCount(count($queries), $trace);
                    $groups = [];
                    foreach ($trace as $index => $entry) {
                        $key = json_encode([$entry['sql'], $entry['bindings']], JSON_THROW_ON_ERROR);
                        $groups[$key] ??= $entry + ['ids' => []];
                        $groups[$key]['ids'][] = $index + 1;
                    }
                    fwrite(STDOUT, $restricted ? "\nPHASE2A1_RESTRICTED_TRACE " : "\nPHASE2A1_TRACE ");
                    fwrite(STDOUT, json_encode(array_values($groups), JSON_THROW_ON_ERROR)."\n");
                }
            }
            fwrite(STDOUT, "\nPHASE2A ".json_encode(['operation' => $operation, 'lines' => $lineCount, 'queries' => count($queries),
                'match_queries' => collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], 'vendor_bill_receipt_matches'))->count()], JSON_THROW_ON_ERROR)."\n");
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^select /i', $query['query']);
                $this->assertStringNotContainsString('for update', $query['query']);
            }

            return [$result, $queries];
        };
        [$prefill, $prefillQueries] = $measure('bill_prefill', fn (): array => $this->bills->prefill($reader, $order, $first));
        $this->assertLessThanOrEqual($restricted ? 18 : 12, count($prefillQueries));
        $this->assertCount(count($prefillQueries), collect($prefillQueries)->unique(fn (array $query): string => json_encode([$query['query'], $query['bindings']], JSON_THROW_ON_ERROR)));
        $this->assertCount(0, collect($prefillQueries)->filter(fn (array $query): bool => str_contains($query['query'], 'from `document_numbers`') || str_starts_with($query['query'], 'select * from `purchase_receipts`')));
        $this->assertCount(1, collect($prefillQueries)->filter(fn (array $query): bool => str_contains($query['query'], 'vendor_bill_receipt_matches')));
        $this->assertCount(2, collect($prefillQueries)->filter(fn (array $query): bool => str_contains($query['query'], 'from `colors`') || str_contains($query['query'], 'from `sizes`')));
        $this->assertCount($lineCount, $prefill['items']);
        foreach ($prefill['items'] as $item) {
            $this->assertSame('0.6667', $item['quantity']);
            $this->assertSame('0.0000', $item['tax_amount']);
            $this->assertSame('0.0000', $item['discount_amount']);
            $this->assertSame($variants->firstWhere('id', $item['variant_id'])->displayName(), $item['description']);
        }
        $all = $this->bills->prefill($reader, $order);
        $this->assertCount(2 * $lineCount, $all['items']);
        $this->assertSame('1.8889', $all['items'][$lineCount]['quantity']);
        if ($lineCount === 100) {
            foreach ($prefillQueries as $query) {
                if (str_contains($query['query'], 'from `purchase_receipt_items`') || str_contains($query['query'], 'SUM(matched_quantity)')) {
                    fwrite(STDOUT, "\nPHASE2A1_EXPLAIN ".json_encode(['sql' => $query['query'], 'bindings' => $query['bindings'],
                        'plan' => DB::select('EXPLAIN '.$query['query'], $query['bindings'])], JSON_THROW_ON_ERROR)."\n");
                }
            }
        }
        $legacy = fn (VendorBill $bill): string => hash('sha256', json_encode([$bill->getAttributes(), $bill->items()->orderBy('id')->get()->map(fn ($item): array => [
            $item->getAttributes(), $item->receiptMatches()->orderBy('id')->get()->map->getAttributes()->all(),
        ])->all()], JSON_THROW_ON_ERROR));
        $expected = $legacy($draft);
        $draft->setRelation('items', collect());
        [$revision, $revisionQueries] = $measure('bill_revision', fn (): string => $this->bills->revision($draft));
        $this->assertSame($expected, $revision);
        $this->assertSame($legacy($posted), $this->bills->revision($posted));
        $this->assertCount(2, $revisionQueries);
        $match = $draft->items()->firstOrFail()->receiptMatches()->orderBy('id')->firstOrFail();
        $match->update(['matched_quantity' => '0.3332']);
        $this->assertNotSame($revision, $this->bills->revision($draft));
        $this->assertSame($legacy($draft), $this->bills->revision($draft));
        $this->reject(fn () => $this->bills->post($this->actor, $draft, $revision));
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertSame($before, $this->inventory());
    }

    private function reject(callable $operation, string $type = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($type, $exception);

            return;
        }
        $this->fail('Expected rejection.');
    }

    public function test_empty_bill_revision_preserves_original_serialization_with_one_read(): void
    {
        $bill = VendorBill::factory()->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->actor->id]);
        $expected = hash('sha256', json_encode([$bill->getAttributes(), []], JSON_THROW_ON_ERROR));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $actual = $this->bills->revision($bill);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($expected, $actual);
        $this->assertCount(1, $queries);
    }

    public function test_prefill_existing_http_boundary_is_preserved_and_invalidates_after_write(): void
    {
        $this->variant->update(['color_id' => Color::factory()->create()->id, 'size_id' => Size::factory()->create()->id]);
        $receipt = $this->receive($this->order(), '50');
        $order = $receipt->purchaseOrder;
        RequestAuthorizationFacts::begin(request());
        try {
            $boundary = RequestAuthorizationFacts::readBoundary();
            foreach (['cold', 'warm'] as $mode) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $result = $this->bills->prefill($this->actor, $order, $receipt);
                    $count = count(DB::getQueryLog());
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
                fwrite(STDOUT, "\nPHASE2A1_HTTP ".json_encode(['mode' => $mode, 'queries' => $count], JSON_THROW_ON_ERROR)."\n");
                $this->assertSame($mode === 'cold' ? 12 : 7, $count);
                $this->assertCount(1, $result['items']);
                $this->assertSame($boundary, RequestAuthorizationFacts::readBoundary());
            }
            Module::where('code', 'purchasing')->update(['is_active' => false]);
            $this->reject(fn () => $this->bills->prefill($this->actor, $receipt->purchaseOrder, $receipt), HttpException::class);
        } finally {
            RequestAuthorizationFacts::end(request());
        }
    }

    public function test_prefill_empty_and_foreign_receipts_preserve_validation_errors(): void
    {
        $order = $this->order();
        try {
            $this->bills->prefill($this->actor, $order);
            $this->fail('Unposted receipts must not be billable.');
        } catch (ValidationException $exception) {
            $this->assertSame(['items' => [__('There are no posted receipt quantities available to bill.')]], $exception->errors());
        }
        $foreign = $this->receive($this->order(), '50');
        try {
            $this->bills->prefill($this->actor, $order, $foreign);
            $this->fail('Foreign receipts must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['receipt' => [__('Select a posted receipt belonging to this purchase order and location.')]], $exception->errors());
        }
    }

    private function prefillReader(): User
    {
        $reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $reader->locations()->attach($this->location);
        app(LocationContext::class)->setFor($reader, $this->location->id);
        $reader->givePermissionTo([PurchasingPermissions::BILLS_VIEW, PurchasingPermissions::BILLS_CREATE,
            PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::RECEIPTS_VIEW]);
        $this->actingAs($reader);

        return $reader;
    }

    public function test_fully_matched_prefill_does_not_need_label_models(): void
    {
        $this->variant->update(['color_id' => Color::factory()->create()->id, 'size_id' => Size::factory()->create()->id]);
        $receipt = $this->receive($this->order(), '50');
        $order = $receipt->purchaseOrder;
        $this->postBill($this->bills->create($this->actor, $this->data($receipt)));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            try {
                $this->bills->prefill($this->actor, $order, $receipt);
                $this->fail('Fully matched receipts must not produce bill lines.');
            } catch (ValidationException $exception) {
                $this->assertSame(['items' => [__('There are no posted receipt quantities available to bill.')]], $exception->errors());
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        fwrite(STDOUT, "\nPHASE2A1_FULLY_MATCHED ".json_encode(['queries' => count($queries), 'label_queries' => collect($queries)->filter(
            fn (array $query): bool => str_contains($query['query'], 'from `products`') || str_contains($query['query'], 'from `product_variants`')
                || str_contains($query['query'], 'from `colors`') || str_contains($query['query'], 'from `sizes`'))->count()], JSON_THROW_ON_ERROR)."\n");
        $this->assertCount(7, $queries);
        $this->assertCount(0, collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], 'from `purchase_order_items`')
            || str_contains($query['query'], 'from `products`') || str_contains($query['query'], 'from `product_variants`')
            || str_contains($query['query'], 'from `colors`') || str_contains($query['query'], 'from `sizes`')));
    }

    public function test_prefill_loads_labels_only_for_still_billable_order_lines(): void
    {
        $otherVariant = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $order = $this->orders->create($this->actor, ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id,
            'order_date' => '2026-10-14', 'items' => collect([$this->variant, $otherVariant])->map(fn (ProductVariant $variant): array => [
                'variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '190'])->all()], (string) Str::uuid7());
        $order = $this->orders->confirm($this->actor, $order, $this->orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $this->receipts->update($this->actor, $receipt, ['items' => $receipt->items()->get()->map(fn ($item): array => [
            'id' => $item->id, 'quantity' => '3', 'unit_cost' => '200'])->all()], $this->receipts->revision($receipt));
        $receipt = $this->receipts->post($this->actor, $receipt, $this->receipts->revision($receipt));
        $data = $this->bills->prefill($this->actor, $order, $receipt) + ['bill_number' => 'FULL-FIRST'];
        $data['items'] = [$data['items'][0]];
        $this->postBill($this->bills->create($this->actor, $data));
        $remainingLine = $receipt->items()->orderBy('id')->get()->last();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $this->bills->prefill($this->actor, $order, $receipt);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $orderLineQuery = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'from `purchase_order_items`'))['query'];
        fwrite(STDOUT, "\nPHASE2A1_PARTLY_MATCHED ".json_encode(['order_line_sql' => $orderLineQuery], JSON_THROW_ON_ERROR)."\n");
        $this->assertCount(1, $result['items']);
        $this->assertSame($otherVariant->id, $result['items'][0]['variant_id']);
        $this->assertSame('3.0000', $result['items'][0]['quantity']);
        $this->assertSame('600.0000', VendorBill::where('status', 'posted')->sole()->total_amount);
        $this->assertStringContainsString('in ('.$remainingLine->purchase_order_item_id.')', $orderLineQuery);
    }

    public function test_prefill_keeps_every_gate_call_when_authorization_reads_are_reused(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $order = $receipt->purchaseOrder;
        $reader = $this->prefillReader();
        $calls = [];
        Gate::after(function (User $user, string $ability) use ($reader, &$calls): void {
            if ($user->is($reader)) {
                $calls[$ability] = ($calls[$ability] ?? 0) + 1;
            }
        });
        $this->assertCount(1, $this->bills->prefill($reader, $order, $receipt)['items']);
        $this->assertSame([PurchasingPermissions::BILLS_VIEW => 1, PurchasingPermissions::BILLS_CREATE => 1,
            PurchasingPermissions::ORDERS_VIEW => 2, PurchasingPermissions::RECEIPTS_VIEW => 2], $calls);
    }

    public function test_prefill_checks_each_permission_again_after_revocation_between_calls(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $order = $receipt->purchaseOrder;
        $reader = $this->prefillReader();
        foreach ([PurchasingPermissions::BILLS_VIEW, PurchasingPermissions::BILLS_CREATE,
            PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::RECEIPTS_VIEW] as $permission) {
            $this->assertCount(1, $this->bills->prefill($reader, $order, $receipt)['items']);
            $reader->revokePermissionTo($permission);
            $this->reject(fn () => $this->bills->prefill($reader, $order, $receipt), HttpException::class);
            $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
            $reader->givePermissionTo($permission);
        }
    }

    public static function changedPrefillFacts(): array
    {
        return array_map(fn (string $change): array => [$change], ['actor', 'location', 'membership', 'team', 'mapping']);
    }

    #[DataProvider('changedPrefillFacts')]
    public function test_prefill_rechecks_changed_authoritative_facts_between_calls(string $change): void
    {
        $receipt = $this->receive($this->order(), '50');
        $order = $receipt->purchaseOrder;
        $reader = $this->prefillReader();
        $this->assertCount(1, $this->bills->prefill($reader, $order, $receipt)['items']);
        match ($change) {
            'actor' => DB::table('users')->where('id', $reader->id)->update(['is_active' => false]),
            'location' => DB::table('locations')->where('id', $this->location->id)->update(['is_active' => false]),
            'membership' => $reader->locations()->detach($this->location),
            'team' => setPermissionsTeamId(Location::create(['name' => 'Foreign prefill', 'type' => 'warehouse', 'is_active' => true])->id),
            'mapping' => Module::where('code', 'taxes')->firstOrFail()->permissions()->attach(
                Permission::where('name', PurchasingPermissions::RECEIPTS_VIEW)->firstOrFail()),
        };
        $this->reject(fn () => $this->bills->prefill($reader, $order, $receipt), HttpException::class);
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
    }

    public function test_prefill_invalidates_facts_when_a_gate_callback_revokes_a_later_permission(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $order = $receipt->purchaseOrder;
        $reader = $this->prefillReader();
        $revoked = false;
        Gate::after(function (User $user, string $ability) use ($reader, &$revoked): void {
            if ($user->is($reader) && $ability === PurchasingPermissions::ORDERS_VIEW && ! $revoked) {
                $revoked = true;
                $reader->revokePermissionTo(PurchasingPermissions::RECEIPTS_VIEW);
            }
        });
        $this->reject(fn () => $this->bills->prefill($reader, $order, $receipt), HttpException::class);
        $this->assertTrue($revoked);
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
    }

    public function test_prefill_does_not_replace_an_existing_requests_nested_transaction_boundary(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $order = $receipt->purchaseOrder;
        RequestAuthorizationFacts::begin(request());
        $state = request()->attributes->get('access_management.authorization_facts');
        try {
            DB::beginTransaction();
            try {
                $this->assertNull(RequestAuthorizationFacts::readBoundary());
                $this->assertCount(1, $this->bills->prefill($this->actor, $order, $receipt)['items']);
                $this->assertNull(RequestAuthorizationFacts::readBoundary());
                $this->assertSame($state, request()->attributes->get('access_management.authorization_facts'));
            } finally {
                DB::rollBack();
            }
            $this->assertNotNull(RequestAuthorizationFacts::readBoundary());
        } finally {
            RequestAuthorizationFacts::end(request());
        }
    }

    private function inventory(): array
    {
        $result = [];
        foreach (['stock_movements', 'stock_balances', 'inventory_cost_entries', 'inventory_cost_states', 'fifo_layers',
            'inventory_standard_cost_revisions', 'product_variants', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_full_billing_does_not_mutate_inventory_receipts_or_reference_prices(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $snapshot = $this->inventory();
        $bill = $this->postBill($this->bills->create($this->actor, $this->data($receipt)));
        $this->assertSame('posted', $bill->status);
        $this->assertSame('10000.0000', $bill->total_amount);
        $this->assertSame('0.0000', $this->bills->remaining($receipt->items->first()));
        $this->assertSame($snapshot, $this->inventory());
        $this->assertSame($this->actor->id, $bill->posted_by);
        $this->assertNotNull($bill->posted_at);
        $this->assertDatabaseHas('auth_events', ['event' => 'vendor_bill_posted', 'actor_user_id' => $this->actor->id]);
    }

    public function test_partial_bills_and_competing_drafts_cannot_overbill(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $competing = $this->bills->create($this->actor, $this->data($receipt, '50', 'competing'));
        $this->assertSame('50.0000', $this->bills->remaining($receipt->items->first()));
        $this->postBill($this->bills->create($this->actor, $this->data($receipt, '20')));
        $this->assertSame('30.0000', $this->bills->remaining($receipt->items->first()));
        $this->postBill($this->bills->create($this->actor, $this->data($receipt, '30', 'INV-2')));
        $this->reject(fn () => $this->postBill($competing));
        $this->assertSame('draft', $competing->refresh()->status);
        $this->assertSame('0.0000', $this->bills->remaining($receipt->items->first()));
    }

    public function test_one_line_can_match_multiple_receipts_for_the_same_order_line(): void
    {
        $order = $this->order();
        $first = $this->receive($order, '20');
        $second = $this->receive($order, '30');
        $data = $this->data($first, '20');
        $data['items'][0]['quantity'] = '50';
        $data['items'][0]['allocations'][] = ['purchase_receipt_item_id' => $second->items->first()->id, 'quantity' => '30'];
        $bill = $this->postBill($this->bills->create($this->actor, $data));
        $this->assertCount(2, $bill->items->first()->receiptMatches);
        $this->assertSame('0.0000', $this->bills->remaining($second->items->first()));
    }

    public function test_duplicate_allocation_is_rejected_and_multiple_lines_are_aggregated(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $data = $this->data($receipt, '30');
        $data['items'][0]['allocations'][] = $data['items'][0]['allocations'][0];
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data = $this->data($receipt, '30');
        $data['items'][] = $data['items'][0];
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data = $this->data($receipt, '20');
        $data['items'][] = $data['items'][0];
        $this->postBill($this->bills->create($this->actor, $data));
        $this->assertSame('10.0000', $this->bills->remaining($receipt->items->first()));
    }

    public function test_invoice_number_is_unique_per_supplier_and_failures_leave_no_partial_records(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $this->bills->create($this->actor, $this->data($receipt, '20'));
        $this->reject(fn () => $this->bills->create($this->actor, $this->data($receipt, '30')));
        $this->assertDatabaseCount('vendor_bills', 1);
        $this->assertDatabaseCount('vendor_bill_items', 1);
        $this->assertDatabaseCount('vendor_bill_receipt_matches', 1);
    }

    public function test_supplier_variant_and_foreign_receipt_manipulation_are_rejected(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $other = $this->receive($this->order(), '50');
        $data = $this->data($receipt);
        $data['supplier_id'] = Supplier::factory()->create()->id;
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data = $this->data($receipt);
        $data['items'][0]['variant_id'] = ProductVariant::factory()->create()->id;
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data = $this->data($receipt);
        $data['items'][0]['allocations'][0]['purchase_receipt_item_id'] = $other->items->first()->id;
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $this->assertDatabaseCount('vendor_bills', 0);
    }

    public function test_unposted_receipts_zero_and_mismatched_quantities_are_rejected(): void
    {
        $order = $this->order();
        $draft = $order->receipts->first();
        $this->reject(fn () => $this->bills->prefill($this->actor, $order, $draft));
        $receipt = $this->receive($order, '20');
        $data = $this->data($receipt, '0');
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data = $this->data($receipt, '10');
        $data['items'][0]['allocations'][0]['quantity'] = '9';
        $this->reject(fn () => $this->bills->create($this->actor, $data));
    }

    public function test_edit_draft_and_stale_revision_protection(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt, '20'));
        $old = $this->bills->revision($bill);
        $bill = $this->bills->update($this->actor, $bill, $this->data($receipt, '30'), $old);
        $this->assertSame('6000.0000', $bill->total_amount);
        $this->reject(fn () => $this->bills->post($this->actor, $bill, $old));
        $this->reject(fn () => $this->bills->update($this->actor, $bill, $this->data($receipt, '20'), $old));
        $this->reject(fn () => $this->bills->cancel($this->actor, $bill, $old));
        $this->assertSame('posted', $this->postBill($bill)->status);
    }

    public function test_standalone_draft_can_update_allowed_fields_without_changing_inventory(): void
    {
        $snapshot = $this->inventory();
        $bill = VendorBill::factory()->create([
            'supplier_id' => $this->supplier->id, 'created_by' => $this->actor->id,
            'purchase_order_id' => null,
        ]);
        $this->assertSame('draft', $bill->status);
        $this->assertNull($bill->purchaseOrder);

        $bill->update(['bill_date' => '2026-10-15', 'due_date' => '2026-11-15', 'notes' => 'Standalone draft']);

        $bill->refresh();
        $this->assertNull($bill->purchase_order_id);
        $this->assertNull($bill->purchaseOrder);
        $this->assertSame('draft', $bill->status);
        $this->assertSame('2026-10-15', $bill->bill_date->toDateString());
        $this->assertSame('2026-11-15', $bill->due_date->toDateString());
        $this->assertSame('Standalone draft', $bill->notes);
        $this->assertSame($snapshot, $this->inventory());
    }

    public function test_standalone_draft_retains_identity_and_posting_protections(): void
    {
        $order = $this->order();
        $bill = VendorBill::factory()->create([
            'supplier_id' => $this->supplier->id, 'created_by' => $this->actor->id,
            'purchase_order_id' => null,
        ]);
        $otherSupplier = Supplier::factory()->create();
        $otherActor = User::factory()->create();
        $before = $bill->refresh()->getAttributes();
        $snapshot = $this->inventory();

        foreach ([
            ['purchase_order_id' => $order->id],
            ['supplier_id' => $otherSupplier->id],
            ['created_by' => $otherActor->id],
            ['uuid' => (string) Str::uuid7()],
            ['status' => 'posted', 'posted_by' => $this->actor->id, 'posted_at' => now()],
        ] as $changes) {
            $this->reject(fn () => $bill->fresh()->forceFill($changes)->save());
            $this->assertSame($before, $bill->fresh()->getAttributes());
        }
        $this->reject(fn () => $bill->delete());
        $this->reject(fn () => $this->bills->create($this->actor, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => null,
            'bill_number' => 'NO-PO', 'bill_date' => '2026-10-15', 'items' => [],
        ]));
        $this->assertSame($before, $bill->fresh()->getAttributes());
        $this->assertDatabaseCount('vendor_bills', 1);
        $this->assertSame($snapshot, $this->inventory());
    }

    public function test_linked_draft_allows_notes_but_cannot_remove_or_replace_its_purchase_order(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        $replacement = $this->order();
        $snapshot = $this->inventory();
        $bill->update(['notes' => 'Linked draft']);
        $this->assertSame('Linked draft', $bill->refresh()->notes);
        $this->assertTrue($bill->purchaseOrder->is($receipt->purchaseOrder));
        $before = $bill->getAttributes();

        foreach ([null, $replacement->id] as $orderId) {
            $this->reject(fn () => $bill->fresh()->update(['purchase_order_id' => $orderId, 'notes' => 'Rewrite']));
            $this->assertSame($before, $bill->fresh()->getAttributes());
        }
        $this->assertSame($snapshot, $this->inventory());
    }

    public function test_posted_header_lines_and_matches_are_immutable_including_stale_models(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $draft = $this->bills->create($this->actor, $this->data($receipt));
        $staleItem = $draft->items->first();
        $staleMatch = $staleItem->receiptMatches->first();
        $bill = $this->postBill($draft);
        $this->reject(fn () => $draft->update(['notes' => 'rewrite']));
        $this->reject(fn () => $staleItem->update(['quantity' => '1']));
        $this->reject(fn () => $staleMatch->update(['matched_quantity' => '1']));
        $this->reject(fn () => $staleMatch->delete());
        $this->reject(fn () => $staleItem->delete());
        $this->reject(fn () => $bill->delete());
        $this->reject(fn () => $this->bills->cancel($this->actor, $bill, $this->bills->revision($bill)));
        $replacement = $this->order();
        $before = $bill->fresh()->getAttributes();
        $snapshot = $this->inventory();
        foreach ([
            ['purchase_order_id' => null],
            ['purchase_order_id' => $replacement->id],
            ['bill_date' => '2026-11-15'],
            ['total_amount' => '0.0000'],
            ['status' => 'draft'],
        ] as $changes) {
            $this->reject(fn () => $bill->fresh()->update($changes));
            $this->assertSame($before, $bill->fresh()->getAttributes());
        }
        $this->assertSame($snapshot, $this->inventory());
    }

    public function test_cancel_draft_is_terminal_and_does_not_consume_quantities(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        $bill = $this->bills->cancel($this->actor, $bill, $this->bills->revision($bill));
        $this->assertSame('cancelled', $bill->status);
        $this->assertDatabaseHas('auth_events', ['event' => 'vendor_bill_draft_cancelled', 'actor_user_id' => $this->actor->id]);
        $this->reject(fn () => $this->postBill($bill));
        $this->assertSame('50.0000', $this->bills->remaining($receipt->items->first()));
    }

    public function test_posting_retry_returns_same_document_without_mutating_history(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->postBill($this->bills->create($this->actor, $this->data($receipt)));
        $before = $bill->getAttributes();
        $retry = $this->bills->post($this->actor, $bill, 'old-request');
        $this->assertSame($before, $retry->getAttributes());
        $this->assertSame(1, AuthEvent::where('event', 'vendor_bill_posted')->count());
        $this->assertDatabaseCount('vendor_bill_receipt_matches', 1);
    }

    public function test_price_variance_is_decimal_safe_and_unconfigured_tax_discount_are_rejected(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $data = $this->data($receipt, '12.5');
        $data['items'][0]['unit_price'] = '210.123456';
        $data['items'][0]['discount_amount'] = '10.1250';
        $data['items'][0]['tax_amount'] = '100.0001';
        $snapshot = $this->inventory();
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data['items'][0]['discount_amount'] = '0';
        $data['items'][0]['tax_amount'] = '0';
        $bill = $this->postBill($this->bills->create($this->actor, $data));
        $this->assertSame('2626.5432', $bill->total_amount);
        $this->assertSame('210.123456', $bill->items->first()->unit_price);
        $this->assertSame('200.000000', $receipt->items->first()->refresh()->unit_cost);
        $this->assertSame('12.5', QuantityFormatter::format($bill->items->first()->quantity));
        $this->assertSame($snapshot, $this->inventory());
    }

    public function test_partial_receipts_and_bills_preserve_zero_tax_evidence_and_inventory_cost(): void
    {
        $tax = Tax::factory()->create(['usage' => 'purchases', 'rate' => '0']);
        $this->variant->product->taxes()->attach($tax);
        $order = $this->order('3');
        $evidence = $order->items()->first()->commercial_snapshot;
        $this->assertSame($tax->id, $evidence['taxes'][0]['id']);
        $firstReceipt = $this->receive($order, '1');
        $secondReceipt = $this->receive($order, '2');
        $tax->update(['is_active' => false, 'name' => 'Retired purchase tax']);
        $inventory = $this->inventory();
        $firstBill = $this->postBill($this->bills->create($this->actor, $this->data($firstReceipt, '1', 'PART-A')));
        $secondBill = $this->postBill($this->bills->create($this->actor, $this->data($secondReceipt, '1', 'PART-B')));
        $finalBill = $this->postBill($this->bills->create($this->actor, $this->data($secondReceipt, '1', 'PART-C')));
        foreach ([$firstBill, $secondBill, $finalBill] as $bill) {
            $this->assertSame($evidence, $bill->items->first()->commercial_snapshot['purchase_order_pricing']);
            $this->assertSame('0.0000', $bill->tax_amount);
            $this->assertSame('0.0000', $bill->discount_amount);
        }
        $this->assertSame('0.0000', $this->bills->remaining($secondReceipt->items->first()));
        $this->assertSame($inventory, $this->inventory());
        $this->assertSame('200.000000', $secondReceipt->items->first()->unit_cost);
        $this->assertSame($finalBill->id, $this->bills->post($this->actor, $finalBill, 'retry')->id);
        $this->reject(fn () => $this->bills->create($this->actor, $this->data($secondReceipt, '1', 'PART-D')));
    }

    public function test_bill_commercial_terms_persist_and_revalidate_without_posting_payment(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $data = $this->data($receipt);
        $data['settlement_terms'] = 'mixed';
        $data['planned_immediate_amount'] = '4000';
        $data['due_date'] = now()->addMonth()->toDateString();
        $bill = $this->bills->create($this->actor, $data);
        $this->assertSame('mixed', $bill->settlement_terms);
        $this->assertSame('4000.0000', $bill->planned_immediate_amount);
        $data['settlement_terms'] = 'credit';
        $data['planned_immediate_amount'] = '0';
        $bill = $this->bills->update($this->actor, $bill, $data, $this->bills->revision($bill));
        $this->assertSame('credit', $bill->settlement_terms);
        $bill = $this->postBill($bill);
        $this->assertSame('credit', $bill->settlement_terms);
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->reject(fn () => $bill->update(['planned_immediate_amount' => '1']));
    }

    public function test_posting_failure_rolls_back_audit_and_status(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        VendorBill::updated(function ($updated): void {
            if ($updated->status === 'posted') {
                throw new \RuntimeException('Injected failure after write');
            }
        });
        $this->reject(fn () => $this->postBill($bill), \RuntimeException::class);
        $this->assertSame('draft', $bill->refresh()->status);
        $this->assertNull($bill->posted_at);
        $this->assertDatabaseMissing('auth_events', ['event' => 'vendor_bill_posted']);
        $this->assertSame('50.0000', $this->bills->remaining($receipt->items->first()));
    }

    public function test_disabled_module_blocks_posting_even_for_superadmin(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $this->reject(fn () => $this->postBill($bill), HttpException::class);
        $this->assertSame('draft', $bill->refresh()->status);
    }

    public function test_location_scope_and_tampered_urls_are_denied(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $data = $this->data($receipt);
        $bill = $this->bills->create($this->actor, $data);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $other = Location::create(['name' => 'Other warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $user->locations()->attach($other);
        app(LocationContext::class)->setFor($user, $other->id);
        $role = Role::create(['name' => 'bill-clerk', 'guard_name' => 'web', 'location_id' => $other->id]);
        $role->givePermissionTo(PurchasingPermissions::all());
        $user->assignRole($role);
        $this->actingAs($user);
        $this->reject(fn () => $this->bills->post($user, $bill, $this->bills->revision($bill)), HttpException::class);
        $this->reject(fn () => $this->bills->create($user, $data), HttpException::class);
        $this->reject(fn () => $this->bills->prefill($user, $receipt->purchaseOrder, $receipt), HttpException::class);
        $this->assertSame(0, VendorBillResource::getEloquentQuery()->count());
        $this->assertFalse(Gate::forUser($user)->allows('view', $bill));
    }

    public function test_filament_create_edit_view_list_and_workflow_links(): void
    {
        $receipt = $this->receive($this->order(), '50');
        Livewire::test(ViewPurchaseOrder::class, ['record' => $receipt->purchaseOrder->uuid])->assertActionVisible('createBill');
        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->assertActionVisible('createBill');
        Livewire::test(CreateVendorBill::class)
            ->fillForm($this->data($receipt, '20'))->call('create')->assertHasNoFormErrors();
        $bill = VendorBill::firstOrFail();
        Livewire::test(EditVendorBill::class, ['record' => $bill->uuid])
            ->fillForm(['notes' => 'draft edited'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('draft edited', $bill->refresh()->notes);
        session()->forget(['filament.notifications', 'filament.claimed_notifications']);
        $page = Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->assertActionVisible('post')->callAction('post');
        $this->assertSame('posted', $bill->refresh()->status, json_encode($page->instance()->getErrorBag()->toArray()));
        $page->assertNotified(__('Bill posted successfully'));
        Livewire::test(ListVendorBills::class)->assertCanSeeTableRecords([$bill]);
        Livewire::test(EditVendorBill::class, ['record' => $bill->uuid])->assertForbidden();
    }

    public function test_english_and_arabic_overbilling_errors_are_visible(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $first = $this->bills->create($this->actor, $this->data($receipt));
        $second = $this->bills->create($this->actor, $this->data($receipt, '50', 'second'));
        $this->postBill($first);
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);
            Livewire::test(ViewVendorBill::class, ['record' => $second->uuid])
                ->callAction('post')->assertNotified(Notification::make()->title(__('Cannot save or post bill'))
                ->body(__('Bill quantity exceeds the remaining posted receipt quantity.'))->danger());
            $this->assertSame('draft', $second->refresh()->status);
        }
    }

    private function workers(array $bills): array
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
            foreach ($bills as $bill) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/VendorBillWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'BILL_ACTOR' => (string) $this->actor->id, 'BILL_ID' => (string) $bill->id,
                    'BILL_REVISION' => $this->bills->revision($bill), 'BILL_START' => (string) $start,
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

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_direct_posting_and_protected_input_fields_are_rejected(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $data = $this->data($receipt);
        $data['status'] = 'posted';
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data = $this->data($receipt);
        $data['items'][0]['unit_price'] = 200.0;
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $data = $this->data($receipt);
        $data['items'] = 'invalid';
        $this->reject(fn () => $this->bills->create($this->actor, $data));
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        $this->reject(fn () => $bill->update(['status' => 'posted', 'posted_by' => $this->actor->id, 'posted_at' => now()]));
        $this->assertSame('draft', $bill->refresh()->status);
    }

    public function test_prefill_from_po_combines_receipts_and_receipt_prefill_limits_source(): void
    {
        $order = $this->order();
        $first = $this->receive($order, '20');
        $second = $this->receive($order, '30');
        $this->assertCount(2, $this->bills->prefill($this->actor, $order)['items']);
        $this->assertCount(1, $this->bills->prefill($this->actor, $order, $first)['items']);
        $page = Livewire::withQueryParams(['receipt' => $second->uuid])->test(CreateVendorBill::class);
        $page->assertSee(__('Remaining billable').': 30')->assertSee(__('Receipt purchase cost').': EGP 200.00')->assertSee(__('PO unit price').': EGP 190.00');
        $this->assertCount(1, $page->get('data.items'));
        $this->assertSame('30', array_values($page->get('data.items'))[0]['quantity']);
        $page = Livewire::withQueryParams(['order' => $order->uuid])->test(CreateVendorBill::class);
        $this->assertCount(2, $page->get('data.items'));
        $page->assertSee(__('Bill total').': EGP 10,000.00');
    }

    public function test_invoice_number_can_repeat_for_a_different_supplier(): void
    {
        $first = $this->receive($this->order(), '50');
        $this->postBill($this->bills->create($this->actor, $this->data($first)));
        $this->supplier = Supplier::factory()->create();
        $second = $this->receive($this->order(), '50');
        $this->postBill($this->bills->create($this->actor, $this->data($second)));
        $this->assertDatabaseCount('vendor_bills', 2);
    }

    public function test_duplicate_invoice_and_required_field_errors_are_visible_in_create_form(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $this->bills->create($this->actor, $this->data($receipt));
        Livewire::test(CreateVendorBill::class)
            ->fillForm($this->data($receipt))->call('create')->assertHasFormErrors(['bill_number'])
            ->assertNotified(Notification::make()->title(__('Cannot save or post bill'))->body(__('This supplier invoice number already exists.'))->danger());
        session()->forget(['filament.notifications', 'filament.claimed_notifications']);
        Livewire::test(CreateVendorBill::class)->call('create')->assertHasFormErrors(['bill_number'])->assertNotified();
    }

    public function test_cancel_action_and_posted_edit_actions_follow_lifecycle(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->callAction('cancel')->assertNotified(__('Draft bill cancelled'));
        $this->assertSame('cancelled', $bill->refresh()->status);
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->assertActionHidden('post')->assertActionHidden('edit');
    }

    public function test_stale_modal_confirmation_explains_error_without_posting(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        $page = Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->mountAction('post');
        $this->bills->update($this->actor, $bill, $this->data($receipt, '20'), $this->bills->revision($bill));
        $page->callMountedAction()->assertNotified(Notification::make()->title(__('Cannot save or post bill'))->body(__('This bill changed. Reload before continuing.'))->danger())->assertNoRedirect();
        $this->assertSame('draft', $bill->refresh()->status);
    }

    public function test_unexpected_posting_error_is_visible_and_rolls_back(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        Exceptions::fake();
        VendorBill::updated(function ($updated): void {
            if ($updated->status === 'posted') {
                throw new \RuntimeException('Private fault details');
            }
        });
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->callAction('post')
            ->assertNotified(Notification::make()->title(__('Cannot save or post bill'))->body(__('The bill operation failed. Refresh and try again, or contact your administrator.'))->danger())->assertNoRedirect();
        $this->assertSame('draft', $bill->refresh()->status);
    }

    public static function preservedCostingMethods(): array
    {
        return ['fifo' => ['fifo'], 'standard' => ['standard']];
    }

    #[DataProvider('preservedCostingMethods')]
    public function test_fifo_layers_and_standard_revisions_remain_unchanged(string $method): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => $method]);
        if ($method === 'standard') {
            $standard = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => '180']);
            InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => '180', 'effective_at' => now()->subDay()]);
        }
        $receipt = $this->receive($this->order(), '50');
        $snapshot = $this->inventory();
        $this->postBill($this->bills->create($this->actor, $this->data($receipt, '50', $method)));
        $this->assertSame($snapshot, $this->inventory());
    }

    public function test_concurrent_complementary_partial_bills_both_post(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $first = $this->bills->create($this->actor, $this->data($receipt, '20'));
        $second = $this->bills->create($this->actor, $this->data($receipt, '30', 'second'));
        $this->assertSame(['posted', 'posted'], array_column($this->workers([$first, $second]), 'status'));
        $this->assertSame('0.0000', $this->bills->remaining($receipt->items->first()));
    }

    public function test_concurrent_duplicate_posting_is_idempotent(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $bill = $this->bills->create($this->actor, $this->data($receipt));
        $snapshot = $this->inventory();
        $this->assertSame(['posted', 'posted'], array_column($this->workers([$bill, $bill]), 'status'));
        $this->assertSame('0.0000', $this->bills->remaining($receipt->items->first()));
        $this->assertSame($snapshot, $this->inventory());
    }

    public function test_concurrent_competing_partial_bills_cannot_exceed_received_quantity(): void
    {
        $receipt = $this->receive($this->order(), '50');
        $first = $this->bills->create($this->actor, $this->data($receipt, '30'));
        $second = $this->bills->create($this->actor, $this->data($receipt, '30', 'second'));
        $this->assertEqualsCanonicalizing(['posted', 'rejected'], array_column($this->workers([$first, $second]), 'status'));
        $this->assertSame('20.0000', $this->bills->remaining($receipt->items->first()));
    }
}
