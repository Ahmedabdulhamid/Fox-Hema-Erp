<?php

namespace App\Modules\Reporting\Observers;

use App\Modules\Reporting\Services\ProfitabilityInvalidation;
use Illuminate\Database\Eloquent\Model;

class CostEvidenceObserver
{
    public function saved(Model $model): void
    {
        app(ProfitabilityInvalidation::class)->evidence($model);
    }

    public function deleted(Model $model): void
    {
        app(ProfitabilityInvalidation::class)->evidence($model);
    }
}
