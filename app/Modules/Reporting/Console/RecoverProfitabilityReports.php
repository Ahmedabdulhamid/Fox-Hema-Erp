<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\ProfitabilityProjectionRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverProfitabilityReports extends Command
{
    protected $signature = 'reporting:profitability-recover {--limit=100 : Maximum source rows per scan and jobs per run (1-500)}';

    protected $description = 'Resume bounded profitability reconciliation and dispatch durable projection work';

    public function handle(ProfitabilityProjectionRecovery $recovery): int
    {
        if (! Schema::hasTable('reporting_profitability_partitions')) {
            $this->info('Profitability Reporting migrations have not been applied.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }
        $recovery->scan($limit);
        $this->info('Dispatched '.$recovery->dispatch($limit).' profitability reporting jobs.');

        return self::SUCCESS;
    }
}
