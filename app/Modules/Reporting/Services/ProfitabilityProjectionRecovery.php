<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Jobs\ProjectProfitabilityDay;

class ProfitabilityProjectionRecovery extends SalesProjectionRecovery
{
    protected string $domain = 'profitability';

    protected string $projectionClass = ProfitabilityProjection::class;

    protected string $jobClass = ProjectProfitabilityDay::class;

    /** @return array<string, string> */
    protected function sources(): array
    {
        return ['sales_deliveries' => 'DATE(posted_at)', 'sales_returns' => 'DATE(posted_at)', 'sales_credit_notes' => 'DATE(posted_at)'];
    }
}
