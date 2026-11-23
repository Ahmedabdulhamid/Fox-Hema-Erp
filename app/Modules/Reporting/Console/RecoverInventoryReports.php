<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\InventoryProjectionRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverInventoryReports extends Command
{
    protected $signature = 'reporting:inventory-recover {--limit=100 : Source rows and jobs per run (1-500)}';

    protected $description = 'Reconcile bounded inventory partitions and dispatch durable projection work';

    public function handle(InventoryProjectionRecovery $recovery): int
    {
        if (! Schema::hasTable('reporting_inventory_partitions')) {
            $this->info('Inventory Reporting migrations have not been applied.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }
        $recovery->scan($limit);
        $this->info('Dispatched '.$recovery->dispatch($limit).' inventory reporting jobs.');

        return self::SUCCESS;
    }
}
