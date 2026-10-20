<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\CommercialMedia;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseLinePricing;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\CreateSalesCreditNote;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\CreateSalesDelivery;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesReturns\Pages\CreateSalesReturn;
use App\Modules\Sales\Services\SalesLinePricing;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesProductSelection;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Taxes\Services\CommercialDefinitions;
use App\Modules\Taxes\Services\TaxManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class CommercialSelectionTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['sales', 'purchasing', 'products', 'taxes', 'discounts'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        $this->branch = Location::create(['name' => 'Commercial UX branch', 'type' => 'branch', 'is_active' => true]);
        $brand = Brand::factory()->create(['name' => 'Fox Essentials', 'is_active' => true]);
        $product = Product::factory()->create(['name' => 'Everyday Oxford', 'brand_id' => $brand->id, 'status' => 'active', 'is_active' => true, 'has_fixed_selling_price' => false]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sku' => 'FOX-OXFORD-001', 'sale_price' => '100']);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->branch->id, 'quantity_on_hand' => '100', 'quantity_available' => '100', 'quantity_reserved' => '0']);
        $this->supplier = Supplier::factory()->create(['name' => 'Oxford Supply']);
    }

    private function input(array $line = [], bool $purchase = false): array
    {
        return ['location_id' => $this->branch->id] + ($purchase ? ['supplier_id' => $this->supplier->id, 'order_date' => now()->toDateString()] : []) + [
            'items' => [$line + ($purchase ? app(PurchaseOrderProductSelection::class)->formState($this->variant) : []) + ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'quantity' => '3', 'unit_price' => '10.123456', 'discount_id' => null, 'tax_ids' => []]],
        ];
    }

    public function test_unassigned_sales_definitions_are_explicitly_selected_and_no_adjustment_is_preserved(): void
    {
        $tax = Tax::factory()->create(['rate' => '14']);
        $discount = Discount::factory()->create(['value' => '10']);
        $pricing = app(SalesLinePricing::class);
        $line = $pricing->line($this->variant, $this->input(['tax_ids' => [$tax->id], 'discount_id' => $discount->id])['items'][0]);
        $this->assertSame('3.0370', $line['discount_amount']);
        $this->assertSame('3.8267', $line['tax_amount']);
        $this->assertSame('31.1601', $line['line_total']);
        $this->variant->product->taxes()->attach($tax);
        $this->variant->product->discounts()->attach($discount);
        $none = $pricing->line($this->variant, $this->input()['items'][0]);
        $this->assertSame('0.0000', $none['discount_amount']);
        $this->assertSame([], $none['commercial_snapshot']['taxes']);
    }

    public function test_purchase_drafts_calculate_supplier_definitions_but_confirmation_preserves_inventory_policy(): void
    {
        $tax = Tax::factory()->create(['usage' => 'purchases', 'rate' => '14']);
        $discount = Discount::factory()->create(['usage' => 'purchases', 'value' => '10']);
        $service = app(PurchaseOrderService::class);
        $order = $service->create($this->actor, $this->input(['tax_ids' => [$tax->id], 'discount_id' => $discount->id], true), (string) Str::uuid7());
        $this->assertSame('31.1601', $order->total_amount);
        $this->assertSame($discount->id, $order->items->first()->commercial_snapshot['discount']['id']);
        try {
            $service->confirm($this->actor, $order, $service->revision($order));
            $this->fail('Inventory policy must be approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.tax_ids', $exception->errors());
            $this->assertArrayHasKey('items.0.discount_id', $exception->errors());
        }
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(0, $order->receipts()->count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('100.0000', StockBalance::first()->quantity_on_hand);
    }

    public function test_wrong_side_inactive_expired_and_order_definitions_cannot_be_selected(): void
    {
        foreach ([['usage' => 'purchases'], ['is_active' => false], ['effective_to' => now()->subDay()->toDateString()]] as $attributes) {
            $tax = Tax::factory()->create($attributes);
            try {
                app(SalesLinePricing::class)->line($this->variant, $this->input(['tax_ids' => [$tax->id]])['items'][0]);
                $this->fail('Ineligible tax accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tax_ids', $exception->errors());
            }
        }
        foreach ([['usage' => 'sales'], ['is_active' => false, 'usage' => 'purchases'], ['scope' => 'order', 'usage' => 'purchases'], ['ends_at' => now()->subSecond(), 'usage' => 'purchases']] as $attributes) {
            $discount = Discount::factory()->create($attributes);
            try {
                app(PurchaseLinePricing::class)->line($this->variant, $this->input(['discount_id' => $discount->id])['items'][0]);
                $this->fail('Ineligible discount accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('discount_id', $exception->errors());
            }
        }
    }

    public function test_inline_creation_uses_managers_and_rejects_unauthorized_or_unsupported_data_transactionally(): void
    {
        $definitions = app(CommercialDefinitions::class);
        foreach (['sales', 'purchases'] as $usage) {
            foreach (['taxes', 'discounts'] as $kind) {
                $data = ['name' => $usage.' '.$kind, 'code' => strtoupper($usage.'-'.$kind), 'usage' => $usage, 'type' => 'percentage',
                    'is_active' => true, 'price_inclusion' => 'excluded', 'scope' => 'product', 'is_stackable' => false,
                    'rate' => $usage === 'sales' ? '14' : '0', 'value' => $usage === 'sales' ? '10' : '0'];
                $id = $definitions->create($this->actor, $kind, $usage, $data);
                $this->assertTrue($definitions->eligible($kind, $usage)->whereKey($id)->exists());
                try {
                    $definitions->create($this->actor, $kind, $usage, $data);
                    $this->fail('Duplicate code accepted.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('code', $exception->errors());
                }
            }
        }
        $this->assertSame(2, Discount::count());
        $this->assertSame(2, Tax::count());
        try {
            $definitions->create($this->actor, 'taxes', 'purchases', ['name' => 'Unsafe', 'code' => 'UNSAFE', 'usage' => 'purchases', 'type' => 'percentage', 'is_active' => true, 'price_inclusion' => 'excluded', 'rate' => '14']);
            $this->fail('Undefined inventory treatment accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate', $exception->errors());
        }
        $unauthorized = User::factory()->create(['is_active' => true]);
        try {
            $definitions->create($unauthorized, 'taxes', 'sales', []);
            $this->fail('Unauthorized inline creation accepted.');
        } catch (HttpException|AuthorizationException $exception) {
            $this->assertNotNull($exception);
        }
        $this->assertSame(2, Tax::count());
    }

    public function test_brand_filtering_resets_dependencies_without_clearing_edit_hydration(): void
    {
        $other = Brand::factory()->create(['name' => 'Other Brand']);
        $selection = app(SalesProductSelection::class);
        $this->assertArrayHasKey($this->variant->product_id, $selection->productOptions($this->actor, $this->branch->id, '', $this->variant->product->brand_id));
        $this->assertSame([], $selection->productOptions($this->actor, $this->branch->id, '', $other->id));
        $service = app(SalesOrderService::class);
        $order = $service->create($this->actor, $this->input(), (string) Str::uuid7());
        $component = Livewire::test(EditSalesOrder::class, ['record' => $order->uuid])->assertSuccessful();
        $items = $component->get('data.items');
        $key = array_key_first($items);
        $this->assertSame((string) $this->variant->product->brand_id, (string) $items[$key]['brand_id']);
        $this->assertSame((string) $this->variant->id, (string) $items[$key]['variant_id']);
        $component->set('data.items.'.$key.'.brand_id', $other->id);
        $this->assertNull($component->get('data.items.'.$key.'.product_id'));
        $this->assertNull($component->get('data.items.'.$key.'.variant_id'));
        $this->assertSame([], $component->get('data.items.'.$key.'.tax_ids'));
    }

    public function test_both_editors_create_definitions_inline_and_immediately_select_them(): void
    {
        foreach ([CreateSalesOrder::class => 'sales', CreatePurchaseOrder::class => 'purchases'] as $page => $usage) {
            $component = Livewire::test($page)->assertSuccessful()->assertSee(__('Brand'))->assertSee(__('Discount'))->assertSee(__('Taxes'));
            $key = array_key_first($component->get('data.items'));
            foreach (['taxes' => 'tax_ids', 'discounts' => 'discount_id'] as $kind => $field) {
                $data = ['name' => 'Inline '.$usage.' '.$kind, 'code' => strtoupper('INLINE-'.$usage.'-'.$kind), 'rate' => '0', 'value' => '0'];
                $component->callAction(TestAction::make('createOption')->schemaComponent('items.'.$key.'.'.$field), $data)->assertHasNoFormErrors();
                $selected = $component->get('data.items.'.$key.'.'.$field);
                $id = ($kind === 'taxes' ? Tax::query() : Discount::query())->where('code', $data['code'])->value('id');
                $this->assertNotNull($id);
                $this->assertContains((string) $id, array_map('strval', (array) $selected));
            }
        }
    }

    public function test_unassigned_snapshot_references_prevent_definition_deletion_and_financial_changes(): void
    {
        $tax = Tax::factory()->create(['rate' => '14']);
        $discount = Discount::factory()->create(['value' => '10']);
        $service = app(SalesOrderService::class);
        $order = $service->create($this->actor, $this->input(['tax_ids' => [$tax->id], 'discount_id' => $discount->id]), (string) Str::uuid7());
        $this->assertFalse(app(TaxManager::class)->canDelete($tax));
        $this->assertFalse(app(DiscountManager::class)->canDelete($discount));
        $before = $order->items->first()->commercial_snapshot;
        try {
            app(TaxManager::class)->save($this->actor, ['rate' => '20'], $tax);
            $this->fail('Used tax changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('record', $exception->errors());
        }
        $this->assertSame($before, $order->fresh()->items->first()->commercial_snapshot);
    }

    public function test_source_line_catalog_filters_remain_authorized_and_preserve_source_identity(): void
    {
        $order = app(SalesOrderService::class)->create($this->actor, $this->input(), (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
        $selectors = app(DocumentSelectors::class);
        $source = $order->items->first();
        $lines = $selectors->lines($this->actor, 'delivery', $order->uuid, '', $this->variant->product->brand_id, $this->variant->product_id);
        $this->assertArrayHasKey($source->uuid, $lines);
        $this->assertSame([], $selectors->lines($this->actor, 'delivery', $order->uuid, '', 999999));
        $this->assertSame([], $selectors->lines($this->actor, 'delivery', $order->uuid, '', null, 999999));
        $detail = $selectors->lineDetail($this->actor, 'delivery', $order->uuid, $source->uuid);
        $this->assertSame($this->variant->product_id, $detail['product_id']);
        $this->assertSame($this->variant->product->brand_id, $detail['brand_id']);
        $this->assertFalse($selectors->sourceProducts(User::factory()->create(), 'delivery', $order->uuid)->exists());
        foreach ([CreateSalesDelivery::class,
            CreateSalesInvoice::class,
            CreateSalesReturn::class,
            CreateSalesCreditNote::class] as $page) {
            Livewire::test($page)->assertSuccessful()->assertSee(__('Brand'))->assertSee(__('Product'));
        }
        $this->assertSame($this->variant->id, $order->fresh()->items->first()->variant_id);
    }

    public function test_missing_private_and_unsafe_images_use_public_fallback(): void
    {
        Storage::fake('public');
        $media = app(CommercialMedia::class);
        foreach ([null, 'private/example.jpg', 'products/../secret.jpg', 'products/missing.jpg'] as $path) {
            $this->assertSame(asset('images/product-placeholder.svg'), $media->publicUrl($path, 'products/'));
        }
        Storage::disk('public')->put('brands/icons/test.svg', '<svg/>');
        $this->assertSame(Storage::disk('public')->url('brands/icons/test.svg'), $media->publicUrl('brands/icons/test.svg', 'brands/icons/'));
    }

    public function test_purchase_edit_preserves_explicit_ids_and_browser_fixture(): void
    {
        $tax = Tax::factory()->create(['name' => 'Purchase zero tax', 'usage' => 'purchases', 'rate' => '0']);
        $discount = Discount::factory()->create(['name' => 'Supplier zero discount', 'usage' => 'purchases', 'value' => '0']);
        $purchase = app(PurchaseOrderService::class)->create($this->actor, $this->input(['tax_ids' => [$tax->id], 'discount_id' => $discount->id], true), (string) Str::uuid7());
        $component = Livewire::test(EditPurchaseOrder::class, ['record' => $purchase->uuid])->assertSuccessful();
        $line = array_values($component->get('data.items'))[0];
        $this->assertSame((string) $this->variant->product->brand_id, (string) $line['brand_id']);
        $this->assertSame([(string) $tax->id], array_map('strval', $line['tax_ids']));
        $this->assertSame((string) $discount->id, (string) $line['discount_id']);
        $component->call('save')->assertHasNoFormErrors();
        $this->assertSame([$tax->id], array_column($purchase->fresh()->items->first()->commercial_snapshot['taxes'], 'id'));
        if (getenv('EXPORT_COMMERCIAL_UX_FIXTURE') === '1') {
            $salesTax = Tax::factory()->create(['name' => 'Sales VAT 14%', 'code' => 'SALES-VAT14', 'rate' => '14']);
            $salesDiscount = Discount::factory()->create(['name' => 'Customer loyalty 10%', 'code' => 'CUSTOMER10', 'value' => '10']);
            $sales = app(SalesOrderService::class)->create($this->actor, $this->input(['tax_ids' => [$salesTax->id], 'discount_id' => $salesDiscount->id]), (string) Str::uuid7());
            Tax::factory()->create(['name' => 'Purchase VAT - policy pending', 'usage' => 'purchases', 'rate' => '14']);
            $database = DB::connection()->getDatabaseName();
            $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $database);
            (new Process(['C:/xampp/mysql/bin/mysqldump.exe', '--host=127.0.0.1', '--port=3338', '--user=root', '--result-file=D:/PointSale/output/commercial-ux-browser-fixture.sql', $database]))->mustRun();
            file_put_contents('D:/PointSale/output/commercial-ux-browser-records.json', json_encode(['sales' => $sales->uuid, 'purchase' => $purchase->uuid, 'brand' => $this->variant->product->brand_id, 'product' => $this->variant->product_id, 'variant' => $this->variant->id]));
        }
    }
}
