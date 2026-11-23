<?php

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Services\SalesProjection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProjectSalesDay implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $locationId, public string $date) {}

    public function handle(SalesProjection $projection): void
    {
        $projection->project($this->locationId, $this->date);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }
}
