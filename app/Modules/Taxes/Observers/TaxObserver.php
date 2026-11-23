<?php

namespace App\Modules\Taxes\Observers;

use App\Modules\Products\Services\ShortCodeGenerator;
use App\Modules\Taxes\Models\Tax;

class TaxObserver
{
    public function creating(Tax $tax): void
    {
        if (blank($tax->code)) {
            $tax->code = app(ShortCodeGenerator::class)->next('TAX', $tax);
        }
    }
}
