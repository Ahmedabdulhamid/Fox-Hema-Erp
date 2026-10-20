<?php

namespace Tests\Concerns;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

trait DocumentTestSetup
{
    use UsesIsolatedDatabase;

    protected User $documentActor;

    protected Location $documentBranch;

    protected ProductVariant $documentVariant;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUpDocuments(): void
    {
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['sales', 'products', 'inventory', 'inventory_costing', 'purchasing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->documentActor = $this->provisionTestSuperAdmin();
        $this->documentActor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->documentActor);
        app(LocationContext::class)->setFor($this->documentActor, null);
        $this->documentBranch = Location::create(['name' => 'Document branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'Document shirt', 'status' => 'active', 'is_active' => true]);
        $this->documentVariant = ProductVariant::factory()->create(['product_id' => $product->id]);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
    }

    protected function documentPurchase(?Location $location = null, string $quantity = '10'): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);
        $order = $service->create($this->documentActor, ['supplier_id' => Supplier::factory()->create(['name' => 'Document Supplier'])->id,
            'location_id' => ($location ?? $this->documentBranch)->id, 'order_date' => today()->toDateString(),
            'items' => [['product_id' => $this->documentVariant->product_id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->documentVariant)['receiving_mode'],
                'variant_id' => $this->documentVariant->id, 'quantity' => $quantity, 'unit_price' => '2']]], (string) Str::uuid7());
        $order = $service->confirm($this->documentActor, $order, $service->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->update($this->documentActor, $receipt, ['items' => [['id' => $receipt->items()->first()->id, 'quantity' => $quantity, 'unit_cost' => '2']]], $receipts->revision($receipt));
        $receipts->post($this->documentActor, $receipt, $receipts->revision($receipt));

        return $order->refresh();
    }

    protected function documentDelivery(int $lineCount = 1): SalesDelivery
    {
        $this->documentPurchase(quantity: (string) max(10, $lineCount));
        $order = app(SalesOrderService::class)->create($this->documentActor, ['location_id' => $this->documentBranch->id, 'customer_name' => 'Document Customer',
            'items' => array_fill(0, $lineCount, ['product_id' => $this->documentVariant->product_id, 'variant_id' => $this->documentVariant->id, 'description' => 'Document shirt',
                'quantity' => $lineCount === 1 ? '10' : '1', 'unit_price' => '10', 'discount_amount' => '0', 'tax_amount' => '0'])], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->documentActor, $order);
        $service = app(SalesDeliveryService::class);
        $delivery = $service->create($this->documentActor, ['sales_order_id' => $order->uuid,
            'items' => $order->items()->get()->map(fn ($line) => ['sales_order_item_id' => $line->uuid, 'quantity' => $line->quantity])->all()], (string) Str::uuid7());

        return app(SalesDeliveryPostingService::class)->post($this->documentActor, $delivery, $service->revision($delivery));
    }
}
