<?php

namespace App\Modules\Products\Observers;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Services\ShortCodeGenerator;

class CategoryObserver
{
    public function creating(Category $category): void
    {
        if (blank($category->code)) {
            $category->code = app(ShortCodeGenerator::class)->next('CAT', $category);
        }
    }
}
