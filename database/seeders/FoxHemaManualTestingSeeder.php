<?php

namespace Database\Seeders;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Discounts\Models\Discount;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\VendorBillService;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class FoxHemaManualTestingSeeder extends Seeder
{
    private const REQUIRED_MODULES = [
        'access_management',
        'products',
        'inventory',
        'inventory_costing',
        'purchasing',
        'sales',
        'payments',
        'treasury',
        'taxes',
        'discounts',
    ];

    public function run(): void
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'fox_hema_pos') {
            throw new LogicException('Manual testing data may only be seeded into the local fox_hema_pos database.');
        }

        $actor = User::query()->where('email', 'admin@foxhema.local')->where('is_active', true)->first();
        if ($actor === null || ! $actor->isGlobalSuperAdmin()) {
            throw new LogicException('The active global Super Admin account is required to seed local testing data.');
        }

        $inactiveModules = Module::query()->whereIn('code', self::REQUIRED_MODULES)->where('is_active', false)->pluck('code')->all();
        if ($inactiveModules !== []) {
            throw new LogicException('Activate the required operational modules before seeding: '.implode(', ', $inactiveModules));
        }

        $locations = Location::query()->where('is_active', true)->get();
        $branches = $locations->where('type', 'branch')->values();
        $warehouse = $locations->firstWhere('type', 'warehouse');
        if ($locations->count() !== 4 || $branches->count() !== 3 || $warehouse === null) {
            throw new LogicException('Expected exactly three active branches and one active central warehouse.');
        }
        if ($branches->firstWhere('name', 'Branch 1') === null) {
            throw new LogicException('The active Branch 1 location is required for customer and payment workflows.');
        }

        if (Product::query()->where('name', 'like', 'FH Local Test %')->exists()) {
            throw new RuntimeException('FH Local Test data already exists; refusing to create duplicate business records.');
        }

        DB::transaction(function () use ($actor, $branches, $warehouse): void {
            app(LocationContext::class)->setFor($actor, null);

            $tax = Tax::factory()->create([
                'name' => 'FH Local Test Sales Tax',
                'code' => 'FH-LOCAL-TEST-SALES-TAX',
                'type' => 'percentage',
                'rate' => '14.0000',
                'price_inclusion' => 'excluded',
                'usage' => 'sales',
                'is_active' => true,
                'effective_from' => null,
                'effective_to' => null,
            ]);
            $discount = Discount::factory()->create([
                'name' => 'FH Local Test Product Discount',
                'code' => 'FH-LOCAL-TEST-DISCOUNT',
                'type' => 'percentage',
                'value' => '5.0000',
                'scope' => 'product',
                'usage' => 'sales',
                'is_active' => true,
                'is_stackable' => false,
                'starts_at' => null,
                'ends_at' => null,
            ]);
            $brand = Brand::factory()->create(['name' => 'FH Local Test Brand', 'is_active' => true]);
            $sizes = Size::query()->where('is_active', true)->orderBy('sort_order')->take(2)->get();
            $colors = Color::query()->where('is_active', true)->orderBy('sort_order')->take(2)->get();
            if ($sizes->count() !== 2 || $colors->count() !== 2) {
                throw new LogicException('Seed active sizes and colors before creating the manual testing catalog.');
            }

            $catalog = [];
            foreach ([
                ['name' => 'FH Local Test FIFO Shirt', 'code' => 'FIFO', 'method' => 'fifo', 'cost' => '10.000000'],
                ['name' => 'FH Local Test AVCO Trousers', 'code' => 'AVCO', 'method' => 'avco', 'cost' => '15.000000'],
                ['name' => 'FH Local Test Standard Jacket', 'code' => 'STANDARD', 'method' => 'standard', 'cost' => '20.000000'],
            ] as $productSpec) {
                $category = Category::factory()->create([
                    'name' => 'FH Local Test '.$productSpec['code'].' Category',
                    'is_active' => true,
                ]);
                InventoryValuationSetting::query()->create([
                    'category_id' => $category->id,
                    'costing_method' => $productSpec['method'],
                    'note' => 'FH local manual testing costing scenario',
                ]);
                $product = Product::factory()->create([
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => $productSpec['name'],
                    'default_sku' => 'FH-LT-'.$productSpec['code'],
                    'status' => 'active',
                    'is_active' => true,
                    'has_fixed_selling_price' => false,
                ]);
                $product->taxes()->attach($tax);
                $product->discounts()->attach($discount);

                foreach ([$sizes[0], $sizes[1]] as $variantIndex => $size) {
                    $cost = number_format((float) $productSpec['cost'] + $variantIndex, 6, '.', '');
                    $variant = ProductVariant::factory()->create([
                        'product_id' => $product->id,
                        'size_id' => $size->id,
                        'color_id' => $colors[$variantIndex]->id,
                        'sku' => 'FH-LT-'.$productSpec['code'].'-'.($variantIndex === 0 ? 'S' : 'M'),
                        'sale_price' => '50.0000',
                        'cost_price' => $cost,
                        'is_active' => true,
                    ]);
                    $catalog[] = ['product' => $product, 'variant' => $variant, 'cost' => $cost, 'discount' => $discount, 'tax' => $tax];

                    if ($productSpec['method'] === 'standard') {
                        $standardCost = InventoryStandardCost::query()->firstOrCreate(
                            ['variant_id' => $variant->id],
                            ['standard_cost' => $cost, 'updated_by' => $actor->id],
                        );
                        InventoryStandardCostRevision::factory()->create([
                            'standard_cost_id' => $standardCost->id,
                            'old_cost' => null,
                            'new_cost' => $cost,
                            'reason' => 'FH local testing standard cost baseline',
                            'changed_by' => $actor->id,
                            'effective_at' => now()->subDay(),
                        ]);
                    }
                }
            }

            $supplier = Supplier::factory()->create([
                'name' => 'FH Local Test Supplier',
                'notes' => 'Local manual testing supplier.',
            ]);
            $orders = app(PurchaseOrderService::class);
            $receipts = app(PurchaseReceiptService::class);
            $bills = app(VendorBillService::class);
            $postedBills = [];
            $receivingLocations = $branches->push($warehouse);

            foreach ($receivingLocations as $location) {
                $order = $orders->create($actor, [
                    'supplier_id' => $supplier->id,
                    'location_id' => $location->id,
                    'order_date' => today()->toDateString(),
                    'notes' => 'FH local manual testing purchase.',
                    'items' => array_map(fn (array $entry): array => [
                        'variant_id' => $entry['variant']->id,
                        'quantity' => '5.0000',
                        'unit_price' => $entry['cost'],
                        'discount_id' => null,
                        'tax_ids' => [],
                    ], $catalog),
                ], (string) Str::uuid7());
                $order = $orders->confirm($actor, $order, $orders->revision($order));
                $receipt = $order->receipts()->firstOrFail();
                $costs = collect($catalog)->keyBy(fn (array $entry): int => (int) $entry['variant']->id);
                $receipt->load('items.purchaseOrderItem');
                $receipt = $receipts->update($actor, $receipt, [
                    'items' => $receipt->items->map(fn ($item): array => [
                        'id' => $item->id,
                        'quantity' => '5.0000',
                        'unit_cost' => $costs->get((int) $item->purchaseOrderItem->variant_id)['cost'],
                    ])->all(),
                ], $receipts->revision($receipt));
                $receipt = $receipts->post($actor, $receipt, $receipts->revision($receipt));

                $billData = $bills->prefill($actor, $order, $receipt);
                $billData['bill_number'] = 'FH-LOCAL-TEST-BILL-'.$location->id;
                $billData['notes'] = 'FH local manual testing vendor bill.';
                $bill = $bills->create($actor, $billData);
                $postedBills[] = $bills->post($actor, $bill, $bills->revision($bill));
            }

            $branch = $branches->firstWhere('name', 'Branch 1');
            $cashbox = TreasuryAccount::query()
                ->where('location_id', $branch->id)
                ->where('type', 'branch_cashbox')
                ->where('is_active', true)
                ->first();
            $paymentMethod = PaymentMethod::query()->where('type', 'cash')->where('is_active', true)->orderBy('id')->first();
            if ($cashbox === null || $paymentMethod === null) {
                throw new LogicException('An active Branch 1 cashbox and cash payment method are required.');
            }

            $treasury = app(TreasuryService::class);
            $openingBalance = $treasury->createDraft($actor, [
                'type' => 'opening_balance',
                'account_id' => $cashbox->id,
                'amount' => '50000.0000',
                'effective_date' => today()->toDateString(),
                'reason' => 'FH local testing opening balance.',
            ], (string) Str::uuid7());
            $treasury->post($actor, $openingBalance);

            $treasury->createDraft($actor, [
                'type' => 'cash_deposit',
                'account_id' => $cashbox->id,
                'amount' => '100.0000',
                'effective_date' => today()->toDateString(),
                'source_type' => 'external_other',
                'source_reference' => (string) Str::uuid7(),
                'reason' => 'FH local testing draft cash deposit.',
            ], (string) Str::uuid7());
            $postedDeposit = $treasury->createDraft($actor, [
                'type' => 'cash_deposit',
                'account_id' => $cashbox->id,
                'amount' => '500.0000',
                'effective_date' => today()->toDateString(),
                'source_type' => 'external_other',
                'source_reference' => (string) Str::uuid7(),
                'reason' => 'FH local testing posted cash deposit.',
            ], (string) Str::uuid7());
            $treasury->post($actor, $postedDeposit);

            $vendorPayment = app(VendorPaymentService::class)->createDraft($actor, [
                'supplier_id' => $supplier->id,
                'location_id' => $branch->id,
                'payment_method_id' => $paymentMethod->id,
                'treasury_account_id' => $cashbox->id,
                'payment_date' => today()->toDateString(),
                'amount' => $postedBills[0]->total_amount,
                'notes' => 'FH local manual testing bill settlement.',
            ], (string) Str::uuid7());
            $vendorPayment = app(VendorPaymentService::class)->post($actor, $vendorPayment);
            app(VendorPaymentAllocationService::class)->allocate(
                $actor,
                $vendorPayment,
                $postedBills[0],
                $vendorPayment->amount,
                (string) Str::uuid7(),
            );

            $customer = app(CustomerAccounts::class)->save($actor, [
                'location_id' => $branch->id,
                'name' => 'FH Local Test Customer',
                'phone' => '01000000001',
                'email' => null,
                'is_active' => true,
            ]);
            $saleVariants = collect($catalog)->groupBy(fn (array $entry): int => (int) $entry['product']->category_id)->map->first()->values();
            $salesOrder = app(SalesOrderService::class)->create($actor, [
                'location_id' => $branch->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'notes' => 'FH local manual testing sale.',
                'items' => $saleVariants->map(fn (array $entry): array => [
                    'product_id' => $entry['product']->id,
                    'variant_id' => $entry['variant']->id,
                    'quantity' => '1.0000',
                    'unit_price' => '50.0000',
                    'discount_id' => $entry['discount']->id,
                    'tax_ids' => [$entry['tax']->id],
                ])->all(),
            ], (string) Str::uuid7());
            $salesOrder = app(SalesOrderConfirmationService::class)->confirm($actor, $salesOrder);

            $deliveryDrafts = app(SalesDeliveryService::class);
            $delivery = $deliveryDrafts->create($actor, [
                'sales_order_id' => $salesOrder->id,
                'items' => $salesOrder->items()->orderBy('id')->get()->map(fn ($item): array => [
                    'sales_order_item_id' => $item->id,
                    'quantity' => $item->quantity,
                ])->all(),
            ], (string) Str::uuid7());
            $delivery = app(SalesDeliveryPostingService::class)->post($actor, $delivery, $deliveryDrafts->revision($delivery));

            $invoiceDrafts = app(SalesInvoiceService::class);
            $invoice = $invoiceDrafts->create($actor, [
                'sales_order_id' => $salesOrder->id,
                'invoice_date' => today()->toDateString(),
                'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                    'sales_delivery_item_id' => $item->id,
                    'quantity' => $item->quantity,
                ])->all(),
            ], (string) Str::uuid7());
            $invoice = $invoiceDrafts->post($actor, $invoice, $invoiceDrafts->revision($invoice));
            app(CustomerAccounts::class)->linkInvoice($actor, $customer, $invoice, [], (string) Str::uuid7());
            app(CustomerPayments::class)->payInvoice($actor, $invoice, [
                'payment_method_id' => $paymentMethod->id,
                'treasury_account_id' => $cashbox->id,
                'amount' => $invoice->total_amount,
                'payment_date' => today()->toDateString(),
                'notes' => 'FH local manual testing invoice payment.',
            ], (string) Str::uuid7());

            $this->command?->info('Seeded FH local manual data: 3 products, 6 variants, 4 locations stocked, 4 purchase orders/receipts/bills, 1 paid sale, 1 allocated vendor payment, and Treasury draft/posted operations.');
        }, 3);
    }
}
