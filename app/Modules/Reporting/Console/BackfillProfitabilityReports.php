<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\ProfitabilityProjection;

class BackfillProfitabilityReports extends BackfillSalesReports
{
    protected $signature = 'reporting:profitability-backfill {--location= : Branch ID} {--from= : First posting date (Y-m-d)} {--until= : Last posting date (Y-m-d), maximum 31 days}';

    protected $description = 'Rebuild at most 31 delivery-basis profitability reporting days for one branch';

    protected string $projectionClass = ProfitabilityProjection::class;
}
