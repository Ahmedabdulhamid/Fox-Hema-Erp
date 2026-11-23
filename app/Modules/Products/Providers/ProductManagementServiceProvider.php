<?php

namespace App\Modules\Products\Providers;

use App\Modules\Products\Console\Commands\CleanupProductImages;
use App\Modules\Products\Console\Commands\RecoverProductImports;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Observers\BrandObserver;
use App\Modules\Products\Observers\CategoryObserver;
use App\Modules\Products\Observers\ProductImageObserver;
use App\Modules\Products\Observers\ProductObserver;
use App\Modules\Products\Observers\ProductVariantObserver;
use App\Modules\Products\Policies\ProductImportPolicy;
use App\Modules\Products\Services\ProductImportImageAllocation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ProductManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ProductImportImageAllocation::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        Product::observe(ProductObserver::class);
        Brand::observe(BrandObserver::class);
        Category::observe(CategoryObserver::class);
        ProductImage::observe(ProductImageObserver::class);
        ProductVariant::observe(ProductVariantObserver::class);
        Gate::policy(ProductImport::class, ProductImportPolicy::class);
        foreach (['Brand', 'Category', 'Size', 'Color', 'Product', 'ProductVariant', 'ProductImage'] as $model) {
            Gate::policy('App\\Modules\\Products\\Models\\'.$model, 'App\\Modules\\Products\\Policies\\'.$model.'Policy');
        }
        if ($this->app->runningInConsole()) {
            $this->commands([CleanupProductImages::class, RecoverProductImports::class]);
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('products:recover-imports')->everyMinute()->withoutOverlapping(5);
            });
        }
    }
}
