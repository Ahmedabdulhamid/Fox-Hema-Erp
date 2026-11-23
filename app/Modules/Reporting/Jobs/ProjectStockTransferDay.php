<?php

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Services\StockTransferProjection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProjectStockTransferDay implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $locationId, public string $date)
    {
        $this->onQueue('reporting');
    }

    public function handle(): void
    {
        app(StockTransferProjection::class)->project($this->locationId, $this->date);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }
}
