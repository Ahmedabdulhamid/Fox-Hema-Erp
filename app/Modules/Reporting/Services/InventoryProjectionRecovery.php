<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Jobs\ProjectInventoryDay;

class InventoryProjectionRecovery extends SalesProjectionRecovery
{
    protected string $domain = 'inventory';

    protected string $projectionClass = InventoryProjection::class;

    protected string $jobClass = ProjectInventoryDay::class;

    protected function sources(): array
    {
        return [
            'stock_movements' => 'DATE(created_at)',
            'stock_balances' => 'DATE(updated_at)',
            'reporting_inventory_partitions' => 'report_date',
        ];
    }
}
