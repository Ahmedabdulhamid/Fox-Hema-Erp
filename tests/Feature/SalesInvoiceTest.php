<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\EditSalesInvoice;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\ViewSalesInvoice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoicePdfData;
use App\Modules\Sales\Services\SalesInvoicePdfService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesInvoiceTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesInvoiceService $service;

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
        $this->assertMatchesRegularExpression('/^(?:8\.|[0-9]+\.[0-9]+\.[0-9]+-MariaDB)/', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['sales', 'products', 'inventory', 'inventory_costing', 'purchasing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->branch = Location::create(['name' => 'Invoice branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'قميص Fox & Hema', 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->configureSalesPricing($this->variant);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $this->service = app(SalesInvoiceService::class);
    }

    private function order(int $lineCount = 1, string $quantity = '3'): SalesOrder
    {
        if (! StockBalance::where('variant_id', $this->variant->id)->exists()) {
            $orders = app(PurchaseOrderService::class);
            $receipts = app(PurchaseReceiptService::class);
            $purchase = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
                'location_id' => $this->branch->id, 'order_date' => '2026-10-17',
                'items' => [['product_id' => $this->variant->product_id,
                    'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'],
                    'variant_id' => $this->variant->id, 'quantity' => '300', 'unit_price' => '2']]], (string) Str::uuid7());
            $purchase = $orders->confirm($this->actor, $purchase, $orders->revision($purchase));
            $receipt = $purchase->receipts()->firstOrFail();
            $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
                'quantity' => '300', 'unit_cost' => '2']]], $receipts->revision($receipt));
            $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        }
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'customer_name' => 'عميل تجريبي', 'customer_phone' => null,
            'items' => array_fill(0, $lineCount, ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'description' => 'Premium cotton shirt / قميص قطني فاخر', 'quantity' => $quantity,
                'unit_price' => '10.123456'])], (string) Str::uuid7());

        return app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
    }

    public function test_invoice_uses_confirmed_pricing_evidence_after_catalog_deactivation(): void
    {
        $order = $this->order();
        $evidence = $order->items->first()->commercial_snapshot;
        $this->variant->product->taxes()->update(['is_active' => false]);
        $this->variant->product->discounts()->update(['is_active' => false]);
        $delivery = $this->deliver($order);
        $invoice = $this->service->create($this->actor, $this->payload($delivery), (string) Str::uuid7());
        $invoice = $this->service->post($this->actor, $invoice, $this->service->revision($invoice));
        $this->assertSame($evidence, $invoice->items->first()->commercial_snapshot['order_pricing']);
        $this->assertSame('0.3704', $invoice->discount_amount);
        $this->assertSame('0.7407', $invoice->tax_amount);
    }

    private function deliver(SalesOrder $order, ?string $quantity = null, bool $post = true): mixed
    {
        $drafts = app(SalesDeliveryService::class);
        $delivery = $drafts->create($this->actor, ['sales_order_id' => $order->id,
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_order_item_id' => $item->id, 'quantity' => $quantity ?? $item->quantity])->all()], (string) Str::uuid7());

        return $post ? app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $drafts->revision($delivery)) : $delivery;
    }

    private function payload(mixed $delivery, string $quantity = '1'): array
    {
        return ['sales_order_id' => $delivery->sales_order_id, 'invoice_date' => '2026-10-17',
            'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => $quantity])->all()];
    }

    private function invoice(mixed $delivery, string $quantity = '1'): SalesInvoice
    {
        return $this->service->create($this->actor, $this->payload($delivery, $quantity), (string) Str::uuid7());
    }

    private function postInvoice(SalesInvoice $invoice): SalesInvoice
    {
        return $this->service->post($this->actor, $invoice, $this->service->revision($invoice));
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

    private function invariants(): array
    {
        $tables = ['stock_balances', 'stock_movements', 'inventory_cost_entries', 'inventory_cost_states',
            'fifo_layers', 'fifo_layer_consumptions', 'sales_stock_reservations', 'sales_reservation_consumptions', 'payments'];
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }

    private function scoped(array $permissions, ?Location $branch = null): User
    {
        $branch ??= $this->branch;
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($branch);
        app(LocationContext::class)->setFor($user, $branch->id);
        $role = Role::create(['name' => 'invoice-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_drafts_do_not_consume_quantities_and_creation_is_idempotent(): void
    {
        $delivery = $this->deliver($this->order());
        $before = $this->invariants();
        $uuid = (string) Str::uuid7();
        $invoice = $this->service->create($this->actor, $this->payload($delivery), $uuid);
        $same = $this->service->create($this->actor, $this->payload($delivery), $uuid);
        $this->assertSame($invoice->id, $same->id);
        $this->assertMatchesRegularExpression('/^'.preg_quote($this->branch->code, '/').'-INV-'.now()->format('Y').'-\d{6}$/', $invoice->invoice_number);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('10.1235', $invoice->subtotal);
        $this->assertSame('0.3704', $invoice->discount_amount);
        $this->assertSame('0.7407', $invoice->tax_amount);
        $this->assertSame('10.4938', $invoice->total_amount);
        $this->assertSame('3.0000', $this->service->lineDetails($this->actor, $delivery->sales_order_id)[$delivery->items->first()->id]['remaining']);
        $this->invoice($delivery, '3');
        $this->reject(fn () => $this->service->create($this->actor, $this->payload($delivery, '2'), $uuid));
        $this->assertSame($before, $this->invariants());
    }

    public function test_partial_invoices_reconcile_exact_order_amounts_and_final_rounding(): void
    {
        $order = $this->order();
        $delivery = $this->deliver($order);
        $before = $this->invariants();
        $numbers = [];
        foreach ([1, 2, 3] as $index) {
            $invoice = $this->postInvoice($this->invoice($delivery));
            $numbers[] = $invoice->invoice_number;
            $this->assertSame((string) (3 - $index).'.0000', $this->service->lineDetails($this->actor, $order->id)[$delivery->items->first()->id]['remaining']);
        }
        $this->assertCount(3, array_unique($numbers));
        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
            $this->assertSame($order->{$field}, (string) BigDecimal::of($order->invoices()->sum($field))->toScale(4));
        }
        $this->assertSame('0.3703', $invoice->discount_amount);
        $this->assertSame('0.7408', $invoice->tax_amount);
        $this->reject(fn () => $this->invoice($delivery));
        $this->assertSame($before, $this->invariants());
    }

    public function test_multiple_deliveries_on_one_invoice_and_partial_posting(): void
    {
        $order = $this->order();
        $first = $this->deliver($order, '1');
        $second = $this->deliver($order, '2');
        $data = $this->payload($first);
        $data['items'][] = $this->payload($second, '2')['items'][0];
        $invoice = $this->postInvoice($this->service->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertCount(2, $invoice->items);
        $this->assertSame($order->total_amount, $invoice->total_amount);
        $this->assertSame(2, DB::table('sales_invoice_delivery_matches')->count());
    }

    public function test_unposted_and_cancelled_deliveries_are_rejected(): void
    {
        $order = $this->order();
        $draft = $this->deliver($order, '1', false);
        $this->reject(fn () => $this->invoice($draft));
        $service = app(SalesDeliveryService::class);
        $service->cancel($this->actor, $draft, $service->revision($draft));
        $this->reject(fn () => $this->invoice($draft));
        $this->assertSame([], $this->service->lineDetails($this->actor, $order->id));
    }

    public function test_duplicate_cross_order_and_cross_branch_matching_are_rejected(): void
    {
        $first = $this->deliver($this->order());
        $second = $this->deliver($this->order());
        $data = $this->payload($first);
        $data['items'][] = $data['items'][0];
        $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        $data['items'] = $this->payload($second)['items'];
        $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        $data = $this->payload($first);
        $data['location_id'] = 999;
        $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_invalid_quantities_and_client_financial_values_are_rejected(): void
    {
        $delivery = $this->deliver($this->order());
        foreach (['0', '-1', '3.0001', '0.00001', '1e0', 'NaN'] as $quantity) {
            $this->reject(fn () => $this->invoice($delivery, $quantity));
        }
        $data = $this->payload($delivery);
        $data['items'][0]['unit_price'] = '0';
        $this->reject(fn () => $this->service->create($this->actor, $data, (string) Str::uuid7()));
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_draft_edit_cancel_stale_revision_and_audit_history(): void
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->invoice($delivery);
        $revision = $this->service->revision($invoice);
        $invoice = $this->service->update($this->actor, $invoice, $this->payload($delivery, '2'), $revision);
        $this->assertSame('2.0000', $invoice->items->first()->quantity);
        $this->reject(fn () => $this->service->cancel($this->actor, $invoice, $revision));
        $invoice = $this->service->cancel($this->actor, $invoice, $this->service->revision($invoice));
        $this->assertSame('cancelled', $invoice->status);
        $this->assertNotNull($invoice->cancelled_at);
        $this->assertSame($this->actor->id, $invoice->cancelled_by);
        $this->assertSame($invoice->id, $this->service->cancel($this->actor, $invoice, 'retry')->id);
        $this->reject(fn () => $this->postInvoice($invoice));
        $this->assertSame('3.0000', $this->service->lineDetails($this->actor, $delivery->sales_order_id)[$delivery->items->first()->id]['remaining']);
        $this->assertDatabaseHas('auth_events', ['event' => 'sales_invoice_cancelled']);
    }

    public function test_posting_is_idempotent_and_posted_invoice_graph_is_immutable(): void
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->invoice($delivery);
        $revision = $this->service->revision($invoice);
        $invoice = $this->service->post($this->actor, $invoice, $revision);
        $after = $this->service->revision($invoice);
        $this->service->post($this->actor, $invoice, $revision);
        $this->assertSame($after, $this->service->revision($invoice->fresh()));
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_invoice_posted')->count());
        $this->reject(fn () => $this->service->update($this->actor, $invoice, $this->payload($delivery), $after));
        $this->reject(fn () => $this->service->cancel($this->actor, $invoice, $after));
        $this->reject(fn () => $invoice->update(['notes' => 'changed']));
        $this->reject(fn () => $invoice->delete());
        $item = $invoice->items->first();
        $this->reject(fn () => $item->update(['unit_price' => '0']));
        $this->reject(fn () => $item->delete());
        $match = $item->deliveryMatches->first();
        $this->reject(fn () => $match->update(['matched_quantity' => '2']));
        $this->reject(fn () => $match->delete());
        $this->reject(fn () => $invoice->increment('total_amount'));
    }

    public function test_stale_allocation_requires_explicit_draft_review(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->invoice($delivery);
        $second = $this->invoice($delivery);
        $third = $this->invoice($delivery);
        $this->postInvoice($first);
        $this->postInvoice($second);
        $this->reject(fn () => $this->postInvoice($third));
        $third = $this->service->update($this->actor, $third, $this->payload($delivery), $this->service->revision($third));
        $this->assertSame('posted', $this->postInvoice($third)->status);
    }

    public function test_branch_permissions_and_inactive_module_enforcement(): void
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->invoice($delivery);
        $user = $this->scoped([SalesPermissions::INVOICES_VIEW, SalesPermissions::INVOICES_CREATE]);
        $this->assertTrue(Gate::forUser($user)->allows('view', $invoice));
        $this->assertFalse(Gate::forUser($user)->allows('post', $invoice));
        $this->reject(fn () => $this->service->post($user, $invoice, $this->service->revision($invoice)), HttpException::class);
        $other = Location::create(['name' => 'Other', 'type' => 'branch', 'is_active' => true]);
        $user = $this->scoped([SalesPermissions::INVOICES_VIEW, SalesPermissions::INVOICES_POST, SalesPermissions::INVOICES_DOWNLOAD], $other);
        $this->assertFalse(Gate::forUser($user)->allows('view', $invoice));
        $this->reject(fn () => $this->service->post($user, $invoice, $this->service->revision($invoice)), HttpException::class);
        $this->assertSame([], $this->service->lineDetails($user, $delivery->sales_order_id));
        Module::where('code', 'sales')->update(['is_active' => false]);
        $this->reject(fn () => $this->service->post($this->actor, $invoice, $this->service->revision($invoice)), HttpException::class);
    }

    public function test_historical_snapshots_and_pdf_values_do_not_follow_catalog_or_settings_changes(): void
    {
        config(['sales-invoices.company.phone' => 'Configured contact']);
        $invoice = $this->postInvoice($this->invoice($this->deliver($this->order()), '3'));
        $snapshot = $invoice->items->first()->commercial_snapshot;
        $this->variant->product->update(['name' => 'Changed name']);
        $this->variant->update(['sku' => 'changed-sku']);
        config(['sales-invoices.company.phone' => 'New contact']);
        $data = app(SalesInvoicePdfData::class)->prepare($invoice->fresh(), 'en');
        $this->assertSame('Configured contact', $data['company']['phone']);
        $this->assertSame($snapshot, $data['items']->first()->commercial_snapshot);
        $html = view('sales::invoices.pdf', $data)->render();
        $this->assertStringContainsString(e($snapshot['product_name']), $html);
        $this->assertStringNotContainsString('Changed name', $html);
        $this->assertSame('1,250.00', app(SalesInvoicePdfData::class)->formatAmount('1250.0000'));
        $this->assertSame('99,999,999,999,999,999.99', app(SalesInvoicePdfData::class)->formatAmount('99999999999999999.9900'));
    }

    public function test_product_pricing_ux_changes_preserve_posted_invoice_and_order_snapshots(): void
    {
        $invoice = $this->postInvoice($this->invoice($this->deliver($this->order()), '3'));
        $beforeInvoice = $invoice->getAttributes();
        $beforeItems = $invoice->items()->get()->map->getAttributes()->all();
        $beforeOrder = $invoice->salesOrder->items()->get()->map->getAttributes()->all();
        $product = $this->variant->product;
        $individualPrice = $this->variant->sale_price;
        foreach ([true, false] as $unified) {
            Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
                ->set('data.has_fixed_selling_price', $unified)->set('data.sale_price', '987.6543')
                ->call('save')->assertHasNoFormErrors();
            $this->assertSame($beforeInvoice, $invoice->fresh()->getAttributes());
            $this->assertSame($beforeItems, $invoice->items()->get()->map->getAttributes()->all());
            $this->assertSame($beforeOrder, $invoice->salesOrder->items()->get()->map->getAttributes()->all());
            $this->assertSame($individualPrice, $this->variant->fresh()->sale_price);
        }
    }

    public function test_arabic_and_english_pdf_generation_with_rtl_and_embedded_fonts(): void
    {
        $invoice = $this->postInvoice($this->invoice($this->deliver($this->order()), '3'));
        $before = $this->service->revision($invoice);
        $pdf = app(SalesInvoicePdfService::class);
        foreach (['ar', 'en'] as $locale) {
            $bytes = $pdf->render($this->actor, $invoice, $locale);
            if (getenv('SALES_INVOICE_PDF_ARTIFACTS') === '1') {
                file_put_contents(base_path('../output/sales-invoice-'.$locale.'.pdf'), $bytes);
            }
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertStringContainsString('/FontFile2', $bytes);
            $reader = new PdfReader(new PdfParser(StreamReader::createByString($bytes)));
            $this->assertSame(1, $reader->getPageCount());
            $html = view('sales::invoices.pdf', app(SalesInvoicePdfData::class)->prepare($invoice, $locale))->render();
            $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html);
            $this->assertStringContainsString(trans('invoices.invoice', [], $locale), $html);
            $this->assertStringContainsString('31.48', $html);
            $this->assertTrue($pdf->configuration($locale)['autoScriptToLang']);
        }
        $this->assertSame($before, $this->service->revision($invoice->fresh()));
    }

    public function test_multipage_pdf_and_escaped_notes(): void
    {
        $delivery = $this->deliver($this->order(55, '1'));
        $data = $this->payload($delivery);
        $data['notes'] = '<img src="https://invalid.example/remote.png"> & customer text';
        $invoice = $this->postInvoice($this->service->create($this->actor, $data, (string) Str::uuid7()));
        foreach (['ar', 'en'] as $locale) {
            $bytes = app(SalesInvoicePdfService::class)->render($this->actor, $invoice, $locale);
            $reader = new PdfReader(new PdfParser(StreamReader::createByString($bytes)));
            $this->assertGreaterThan(1, $reader->getPageCount());
            $html = view('sales::invoices.pdf', app(SalesInvoicePdfData::class)->prepare($invoice, $locale))->render();
            $this->assertStringContainsString('&lt;img', $html);
            $this->assertStringNotContainsString('<img src="https:', $html);
            $this->assertStringContainsString('<thead>', $html);
        }
    }

    public function test_pdf_http_download_authorization_and_filename(): void
    {
        $delivery = $this->deliver($this->order());
        $draft = $this->invoice($delivery);
        $url = fn ($locale): string => route('sales.invoices.pdf', ['invoice' => $draft->uuid, 'locale' => $locale]);
        $this->get($url('en'))->assertForbidden();
        $invoice = $this->postInvoice($draft);
        $this->get($url('ar'))->assertOk()->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="'.$invoice->invoice_number.'-AR.pdf"');
        $this->get($url('xx'))->assertNotFound();
        $user = $this->scoped([SalesPermissions::INVOICES_VIEW]);
        $this->get($url('en'))->assertForbidden();
        $other = Location::create(['name' => 'Elsewhere', 'type' => 'branch', 'is_active' => true]);
        $this->scoped([SalesPermissions::INVOICES_VIEW, SalesPermissions::INVOICES_DOWNLOAD], $other);
        $this->get($url('en'))->assertForbidden();
        auth()->logout();
        $this->getJson($url('en'))->assertUnauthorized();
    }

    public function test_pdf_rejects_tampered_posting_evidence(): void
    {
        $invoice = $this->postInvoice($this->invoice($this->deliver($this->order())));
        DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->update(['unit_price' => '0']);
        $this->reject(fn () => app(SalesInvoicePdfService::class)->render($this->actor, $invoice, 'en'));
        $this->reject(fn () => $this->postInvoice($invoice->fresh()));
    }

    public function test_filament_create_edit_list_view_and_post_permissions(): void
    {
        $delivery = $this->deliver($this->order());
        Livewire::test(CreateSalesInvoice::class)->fillForm($this->payload($delivery))->call('create')->assertHasNoFormErrors();
        $invoice = SalesInvoice::firstOrFail();
        Livewire::test(EditSalesInvoice::class, ['record' => $invoice->uuid])->fillForm($this->payload($delivery, '2'))->call('save')->assertHasNoFormErrors();
        $this->assertSame('2.0000', $invoice->items()->first()->quantity);
        Livewire::test(ListSalesInvoices::class)->assertCanSeeTableRecords([$invoice]);
        Livewire::test(ViewSalesInvoice::class, ['record' => $invoice->uuid])->assertActionVisible('post')->assertActionHidden('download_ar');
        Livewire::test(ViewSalesInvoice::class, ['record' => $invoice->uuid])
            ->callAction('post', data: ['revision' => $this->service->revision($invoice->fresh())])->assertNotified();
        $invoice = $invoice->fresh();
        Livewire::test(ViewSalesInvoice::class, ['record' => $invoice->uuid])->assertActionHidden('post')->assertActionVisible('download_ar');
        Livewire::test(EditSalesInvoice::class, ['record' => $invoice->uuid])->assertForbidden();
    }

    private function race(array $jobs): array
    {
        $configuration = config('database.connections.mysql');
        $processes = [];
        $start = microtime(true) + 2;
        try {
            foreach ($jobs as $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/SalesOrderWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'SALES_ACTOR' => (string) $this->actor->id, 'SALES_START' => (string) $start, 'SALES_ALLOW_REJECTION' => '1',
                ] + $job);
                $process->setTimeout(90)->start();
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

    public function test_invoice_permissions_register_without_resetting_existing_grants(): void
    {
        $this->scoped([SalesPermissions::INVOICES_VIEW]);
        $role = Role::where('name', 'like', 'invoice-%')->firstOrFail();
        $before = $role->permissions()->pluck('permissions.name')->all();
        app(ModuleRegistry::class)->register();
        $this->assertSame($before, $role->permissions()->pluck('permissions.name')->all());
        $this->assertTrue((bool) Module::where('code', 'sales')->value('is_active'));
        foreach (['view', 'create', 'update', 'post', 'cancel', 'download'] as $ability) {
            $this->assertDatabaseHas('permissions', ['name' => 'sales.invoices.'.$ability]);
        }
    }

    public function test_duplicate_match_database_constraint_and_historical_number_collision(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->invoice($delivery);
        $match = $first->items->first()->deliveryMatches->first();
        $this->reject(fn () => $first->items->first()->deliveryMatches()->create([
            'sales_delivery_item_id' => $match->sales_delivery_item_id, 'matched_quantity' => '1',
        ]), QueryException::class);
        DB::table('document_number_sequences')->where('location_id', $this->branch->id)->where('document_type', 'INV')->update(['last_number' => 0]);
        $second = $this->invoice($delivery);
        $this->assertNotSame($first->invoice_number, $second->invoice_number);
        $this->assertSame($this->branch->code.'-INV-'.now()->format('Y').'-000001', $first->fresh()->invoice_number);
        $this->assertSame($this->branch->code.'-INV-'.now()->format('Y').'-000002', $second->invoice_number);
    }

    public function test_historical_matching_corruption_and_unauthorized_creation_fail_closed(): void
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->postInvoice($this->invoice($delivery));
        DB::table('sales_invoice_delivery_matches')->where('sales_invoice_item_id', $invoice->items->first()->id)->update(['matched_quantity' => '0.5000']);
        $this->reject(fn () => $this->invoice($delivery));
        $user = $this->scoped([SalesPermissions::INVOICES_VIEW]);
        $this->reject(fn () => $this->service->create($user, $this->payload($delivery), (string) Str::uuid7()), HttpException::class);
    }

    public function test_concurrent_competing_invoices_cannot_overinvoice(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->invoice($delivery, '3');
        $second = $this->invoice($delivery, '3');
        $before = $this->invariants();
        $results = $this->race(array_map(fn ($invoice): array => [
            'SALES_OPERATION' => 'invoice-post', 'SALES_INVOICE' => (string) $invoice->id,
            'SALES_REVISION' => $this->service->revision($invoice),
        ], [$first, $second]));
        $this->assertCount(1, array_filter($results, fn ($result): bool => isset($result['rejected'])));
        $this->assertSame(1, SalesInvoice::where('status', 'posted')->count());
        $this->assertSame($before, $this->invariants());
    }

    public function test_concurrent_repeat_post_and_number_generation(): void
    {
        $delivery = $this->deliver($this->order());
        $jobs = [];
        foreach ([1, 2] as $index) {
            $jobs[] = ['SALES_OPERATION' => 'invoice-create', 'SALES_PAYLOAD' => json_encode($this->payload($delivery), JSON_THROW_ON_ERROR),
                'SALES_SUBMISSION' => (string) Str::uuid7()];
        }
        $results = $this->race($jobs);
        $this->assertNotSame($results[0]['number'], $results[1]['number']);
        $invoice = SalesInvoice::findOrFail($results[0]['id']);
        $job = ['SALES_OPERATION' => 'invoice-post', 'SALES_INVOICE' => (string) $invoice->id,
            'SALES_REVISION' => $this->service->revision($invoice)];
        $results = $this->race([$job, $job]);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, SalesInvoice::where('status', 'posted')->count());
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_invoice_posted')->count());
    }
}
