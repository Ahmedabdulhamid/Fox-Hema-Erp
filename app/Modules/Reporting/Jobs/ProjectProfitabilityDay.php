<?php

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Services\ProfitabilityProjection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProjectProfitabilityDay implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $locationId, public string $date)
    {
        $this->onQueue('reporting');
    }

    public function handle(ProfitabilityProjection $projection): void
    {
        $projection->project($this->locationId, $this->date);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }
}
