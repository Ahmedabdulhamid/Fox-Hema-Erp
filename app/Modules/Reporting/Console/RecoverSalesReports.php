<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\SalesProjectionRecovery;
use Illuminate\Console\Command;

class RecoverSalesReports extends Command
{
    protected $signature = 'reporting:sales-recover {--limit=100 : Maximum source rows per scan and jobs per run (1-500)}';

    protected $description = 'Resume bounded sales reconciliation and dispatch durable projection work';

    public function handle(SalesProjectionRecovery $recovery): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }
        $recovery->scan($limit);
        $this->info('Dispatched '.$recovery->dispatch($limit).' sales reporting jobs.');

        return self::SUCCESS;
    }
}
