<?php

use App\Http\Controllers\DownloadBackupArchiveController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PosOfflineShellController;
use App\Http\Controllers\PosOfflineSyncController;
use App\Http\Controllers\ProductExportDownloadController;
use App\Http\Controllers\ProductImportDownloadController;
use App\Http\Controllers\SalesCreditNotePdfController;
use App\Http\Controllers\SalesInvoicePdfController;
use App\Http\Controllers\VariantBarcodeLabelController;
use App\Http\Controllers\VendorBillPdfController;
use App\Modules\AccessManagement\Http\Middleware\EnforceAccountSecurity;
use Illuminate\Support\Facades\Route;

Route::post('/language/{locale}', LocaleController::class)->name('locale.update');

Route::get('/product-exports/{token}', ProductExportDownloadController::class)
    ->middleware(['auth', EnforceAccountSecurity::class, 'signed', 'throttle:30,1'])
    ->whereUuid('token')->name('products.export.download');

Route::get('/product-imports/{kind}/{uuid?}', ProductImportDownloadController::class)
    ->middleware(['auth', EnforceAccountSecurity::class, 'throttle:30,1'])
    ->whereIn('kind', ['template', 'example', 'source', 'errors'])->whereUuid('uuid')->name('products.import.download');

Route::get('/backups/{uuid}/download', DownloadBackupArchiveController::class)
    ->middleware(['auth', EnforceAccountSecurity::class])->whereUuid('uuid')->name('backups.download');

Route::get('/admin/pos-terminal/sw.js', [PosOfflineShellController::class, 'worker'])
    ->middleware(['auth', EnforceAccountSecurity::class])->name('pos.offline.worker');
Route::post('/pos/devices/{device:id}/offline-sync', PosOfflineSyncController::class)
    ->middleware(['auth', EnforceAccountSecurity::class])->name('pos.offline.sync');
Route::post('/pos/devices/{device:id}/offline-outbox', [PosOfflineSyncController::class, 'outbox'])
    ->middleware(['auth', EnforceAccountSecurity::class])->name('pos.offline.outbox');
Route::post('/pos/devices/{device:id}/offline-return-sync', [PosOfflineSyncController::class, 'syncReturn'])
    ->middleware(['auth', EnforceAccountSecurity::class])->name('pos.offline.return-sync');

Route::get('/products/variants/{variant:uuid}/barcode-label', VariantBarcodeLabelController::class)
    ->middleware(['auth', EnforceAccountSecurity::class])->name('products.variants.barcode-label');

Route::get('/sales/invoices/{invoice:uuid}/pdf/{locale}', SalesInvoicePdfController::class)
    ->middleware(['auth', EnforceAccountSecurity::class])
    ->whereIn('locale', ['ar', 'en'])->name('sales.invoices.pdf');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sales/credit-notes/{note:uuid}/pdf/{locale}', SalesCreditNotePdfController::class)
    ->middleware(['auth', EnforceAccountSecurity::class])
    ->whereIn('locale', ['ar', 'en'])->name('sales.credit-notes.pdf');

Route::get('/purchasing/vendor-bills/{bill:uuid}/pdf/{locale}', VendorBillPdfController::class)
    ->middleware(['auth', EnforceAccountSecurity::class])
    ->whereIn('locale', ['ar', 'en'])->name('purchasing.vendor-bills.pdf');
