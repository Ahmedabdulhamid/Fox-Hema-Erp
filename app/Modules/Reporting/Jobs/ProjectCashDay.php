<?php

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Services\CashProjection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProjectCashDay implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $domain, public int $scopeId, public string $date)
    {
        CashProjection::forDomain($domain);
        $this->onQueue('reporting');
    }

    public function handle(): void
    {
        CashProjection::forDomain($this->domain)->project($this->scopeId, $this->date);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }
}
