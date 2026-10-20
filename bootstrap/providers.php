<?php

use App\Modules\AccessManagement\Providers\AccessManagementServiceProvider;
use App\Modules\Products\Providers\ProductManagementServiceProvider;
use App\Modules\Taxes\Providers\TaxesDiscountsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AccessManagementServiceProvider::class,
    ProductManagementServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
    TaxesDiscountsServiceProvider::class,
];
