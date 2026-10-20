<?php

namespace App\Modules\Products\Observers;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductImageManager;

class ProductObserver
{
    public function deleting(Product $product): void
    {
        $product->setRelation('images', $product->images()->get());
    }

    public function deleted(Product $product): void
    {
        foreach ($product->images as $image) {
            app(ProductImageManager::class)->enqueue($image->disk, $image->path);
        }
    }
}
