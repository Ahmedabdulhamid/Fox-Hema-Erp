<?php

namespace App\Providers;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleDiscovery;
use App\Modules\AccessManagement\Services\ModuleMigrator;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Policies\QuarantineReceiptPolicy;
use App\Modules\Inventory\Policies\SizeAllocationPolicy;
use App\Modules\Inventory\Policies\StockBalancePolicy;
use App\Modules\Inventory\Policies\StockMovementPolicy;
use App\Modules\InventoryCosting\Services\SalesReturnValuationService;
use App\Modules\InventoryCosting\Services\StockIssueValuationService;
use App\Modules\Products\Filament\VariantInventoryValuationPanel;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Policies\PurchaseOrderPolicy;
use App\Modules\Purchasing\Policies\PurchaseReceiptPolicy;
use App\Modules\Purchasing\Policies\SupplierPolicy;
use App\Modules\Purchasing\Policies\VendorBillPolicy;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Policies\SalesCreditNotePolicy;
use App\Modules\Sales\Policies\SalesDeliveryPolicy;
use App\Modules\Sales\Policies\SalesInvoicePolicy;
use App\Modules\Sales\Policies\SalesOrderPolicy;
use App\Modules\Sales\Policies\SalesReturnPolicy;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(StockIssueValuationService::class);
        $this->app->scoped(SalesReturnValuationService::class);
        $this->app->scoped(DocumentNumberService::class);
        $this->app->extend('migrator', function (Migrator $migrator, $app): ModuleMigrator {
            $ordered = new ModuleMigrator($app['migration.repository'], $app['db'], $app['files'], $app['events']);
            foreach ($migrator->paths() as $path) {
                $ordered->path($path);
            }

            return $ordered;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::component('variant-inventory-valuation', VariantInventoryValuationPanel::class);
        $this->loadMigrationsFrom(app(ModuleDiscovery::class)->migrationPaths());
        Gate::policy(SalesOrder::class, SalesOrderPolicy::class);
        Gate::policy(SalesDelivery::class, SalesDeliveryPolicy::class);
        Gate::policy(SalesInvoice::class, SalesInvoicePolicy::class);
        Gate::policy(SalesCreditNote::class, SalesCreditNotePolicy::class);
        Gate::policy(SalesReturn::class, SalesReturnPolicy::class);
        Gate::policy(QuarantineReceipt::class, QuarantineReceiptPolicy::class);
        Gate::policy(StockBalance::class, StockBalancePolicy::class);
        Gate::policy(SizeAllocation::class, SizeAllocationPolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(PurchaseReceipt::class, PurchaseReceiptPolicy::class);
        Gate::policy(VendorBill::class, VendorBillPolicy::class);
        $this->loadViewsFrom(app_path('Modules/Purchasing/Resources/views'), 'purchasing');
        $this->loadViewsFrom(app_path('Modules/Sales/Resources/views'), 'sales');
    }
}
