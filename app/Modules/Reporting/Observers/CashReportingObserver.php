<?php

namespace App\Modules\Reporting\Observers;

use App\Modules\Reporting\Services\CashReportingInvalidation;
use Illuminate\Database\Eloquent\Model;

/** Run inside the source transaction; durable dirty revisions and epochs commit atomically. */
class CashReportingObserver
{
    public function saved(Model $model): void
    {
        app(CashReportingInvalidation::class)->changed($model);
    }

    public function deleted(Model $model): void
    {
        app(CashReportingInvalidation::class)->changed($model);
    }
}
