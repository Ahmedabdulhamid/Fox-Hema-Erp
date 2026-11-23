<?php

namespace App\Modules\Products\Jobs;

use App\Modules\Products\Models\ProductExport;
use App\Modules\Products\Services\ProductExport as ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunProductExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 840;

    public int $backoff = 60;

    public function __construct(public int $exportId)
    {
        $this->onConnection('product_exports_database')->onQueue('product-exports');
    }

    /**
     * Execute the job.
     */
    public function handle(ExportService $service): void
    {
        $service->run($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        ProductExport::whereKey($this->exportId)->where('status', '!=', 'completed')
            ->where(fn (Builder $query): Builder => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()->addMinute()))
            ->update(['status' => 'failed', 'lease_until' => null, 'lease_token' => null, 'error' => __('product_export.failed')]);
    }
}
