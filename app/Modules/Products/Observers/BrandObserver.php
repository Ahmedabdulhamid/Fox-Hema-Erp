<?php

namespace App\Modules\Products\Observers;

use App\Modules\Products\Models\Brand;
use App\Modules\Products\Services\ProductImageManager;

class BrandObserver
{
    public function updated(Brand $brand): void
    {
        if ($brand->wasChanged('icon') && filled($path = $brand->getRawOriginal('icon'))) {
            app(ProductImageManager::class)->enqueue('public', $path);
        }
    }

    public function deleted(Brand $brand): void
    {
        if (filled($brand->icon)) {
            app(ProductImageManager::class)->enqueue('public', $brand->icon);
        }
    }
}
