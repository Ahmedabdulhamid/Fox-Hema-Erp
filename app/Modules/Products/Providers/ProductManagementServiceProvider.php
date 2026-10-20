<?php

namespace App\Modules\Products\Providers;

use App\Modules\Products\Console\Commands\CleanupProductImages;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Observers\BrandObserver;
use App\Modules\Products\Observers\ProductImageObserver;
use App\Modules\Products\Observers\ProductObserver;
use App\Modules\Products\Observers\ProductVariantObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ProductManagementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Product::observe(ProductObserver::class);
        Brand::observe(BrandObserver::class);
        ProductImage::observe(ProductImageObserver::class);
        ProductVariant::observe(ProductVariantObserver::class);
        foreach (['Brand', 'Category', 'Size', 'Color', 'Product', 'ProductVariant', 'ProductImage'] as $model) {
            Gate::policy('App\\Modules\\Products\\Models\\'.$model, 'App\\Modules\\Products\\Policies\\'.$model.'Policy');
        }
        if ($this->app->runningInConsole()) {
            $this->commands([CleanupProductImages::class]);
        }
    }
}
