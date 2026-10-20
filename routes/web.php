<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\SalesCreditNotePdfController;
use App\Http\Controllers\SalesInvoicePdfController;
use App\Http\Controllers\VariantBarcodeLabelController;
use App\Http\Controllers\VendorBillPdfController;
use App\Modules\AccessManagement\Http\Middleware\EnforceAccountSecurity;
use Illuminate\Support\Facades\Route;

Route::post('/language/{locale}', LocaleController::class)->name('locale.update');

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
