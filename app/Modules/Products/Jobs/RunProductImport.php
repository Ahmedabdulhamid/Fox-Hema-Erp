<?php

namespace App\Modules\Products\Jobs;

use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Services\ProductImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunProductImport implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public int $importId)
    {
        $this->onConnection('products_database')->onQueue('products');
        $this->tries = (int) config('product-import.tries');
        $this->timeout = (int) config('product-import.timeout');
    }

    public function backoff(): array
    {
        return config('product-import.backoff');
    }

    public function handle(ProductImportProcessor $processor): void
    {
        $processor->run($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        ProductImport::whereKey($this->importId)->whereIn('status', ['uploaded', 'preparing', 'queued', 'running'])
            ->where(fn (Builder $query): Builder => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->update(['status' => 'paused', 'errors' => ['queue' => [__('product_import.attempts_exhausted')]]]);
    }
}
