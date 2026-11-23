<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosSessionTender;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorBillItem;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
use App\Modules\Reporting\Console\BackfillCashReports;
use App\Modules\Reporting\Console\BackfillInventoryReports;
use App\Modules\Reporting\Console\BackfillProfitabilityReports;
use App\Modules\Reporting\Console\BackfillPurchasingReports;
use App\Modules\Reporting\Console\BackfillSalesReports;
use App\Modules\Reporting\Console\BackfillStockTransferReports;
use App\Modules\Reporting\Console\RecoverCashReports;
use App\Modules\Reporting\Console\RecoverInventoryReports;
use App\Modules\Reporting\Console\RecoverProfitabilityReports;
use App\Modules\Reporting\Console\RecoverPurchasingReports;
use App\Modules\Reporting\Console\RecoverSalesReports;
use App\Modules\Reporting\Console\RecoverStockTransferReports;
use App\Modules\Reporting\Observers\CashReportingObserver;
use App\Modules\Reporting\Observers\CostEvidenceObserver;
use App\Modules\Reporting\Observers\PurchasingReportingObserver;
use App\Modules\Reporting\Observers\StockTransferReportingObserver;
use App\Modules\Reporting\Services\InventoryReportingInvalidation;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferItem;
use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryEntry;
use App\Modules\Treasury\Models\TreasuryOperation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(app_path('Modules/Reporting/Resources/views'), 'reporting');
        foreach ([StockTransfer::class,
            StockTransferItem::class,
            StockTransferShipment::class,
            StockTransferShipmentItem::class,
            StockTransferReceipt::class,
            StockTransferReceiptItem::class,
            StockMovement::class] as $model) {
            $model::observe(StockTransferReportingObserver::class);
        }
        foreach ([PurchaseOrder::class, PurchaseOrderItem::class,
            PurchaseReceipt::class, PurchaseReceiptItem::class,
            VendorBill::class, VendorBillItem::class,
            VendorPayment::class, VendorPaymentAllocation::class] as $model) {
            $model::observe(PurchasingReportingObserver::class);
        }
        foreach ([TreasuryAccount::class, TreasuryOperation::class, TreasuryEntry::class,
            PosSession::class, PosTransaction::class, PosDevice::class, PosCashMovement::class, PosSessionTender::class,
            Payment::class, PaymentRefund::class,
            SalesInvoice::class, SalesReturn::class, SalesCreditNote::class] as $model) {
            $model::observe(CashReportingObserver::class);
        }
        $this->app->singleton(InventoryReportingInvalidation::class);
        DB::listen(fn ($event) => app(InventoryReportingInvalidation::class)->written($event));
        foreach ([StockMovement::class, InventoryCostEntry::class, FifoLayerConsumption::class, SalesReturnCostAllocation::class] as $model) {
            $model::observe(CostEvidenceObserver::class);
        }
        if ($this->app->runningInConsole()) {
            $this->commands([BackfillStockTransferReports::class, RecoverStockTransferReports::class]);
            $this->commands([BackfillPurchasingReports::class, RecoverPurchasingReports::class]);
            $this->commands([BackfillCashReports::class, RecoverCashReports::class]);
            $this->commands([BackfillSalesReports::class, RecoverSalesReports::class, BackfillProfitabilityReports::class, RecoverProfitabilityReports::class, BackfillInventoryReports::class, RecoverInventoryReports::class]);
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('reporting:stock_transfer-recover --limit=100')->everyMinute()->withoutOverlapping(5);
                $schedule->command('reporting:purchasing-recover --limit=100')->everyMinute()->withoutOverlapping(5);
                $schedule->command('reporting:sales-recover --limit=100')->everyMinute()->withoutOverlapping(5);
                $schedule->command('reporting:profitability-recover --limit=100')->everyMinute()->withoutOverlapping(5);
                $schedule->command('reporting:inventory-recover --limit=100')->everyMinute()->withoutOverlapping(5);
                $schedule->command('reporting:cash-recover treasury --limit=100')->everyMinute()->withoutOverlapping(5);
                $schedule->command('reporting:cash-recover pos --limit=100')->everyMinute()->withoutOverlapping(5);
            });
        }
    }
}
