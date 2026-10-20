<?php

namespace App\Modules\Products\Observers;

use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Services\ProductImageManager;

class ProductImageObserver
{
    public function updated(ProductImage $image): void
    {
        if ($image->wasChanged(['disk', 'path'])) {
            app(ProductImageManager::class)->enqueue($image->getRawOriginal('disk'), $image->getRawOriginal('path'));
        }
    }

    public function deleted(ProductImage $image): void
    {
        app(ProductImageManager::class)->enqueue($image->disk, $image->path);
    }
}
