<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\CashProjectionRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverCashReports extends Command
{
    protected $signature = 'reporting:cash-recover {domain : treasury or pos} {--limit=100}';

    protected $description = 'Bounded durable Treasury and POS reporting recovery and reconciliation';

    public function handle(): int
    {
        $domain = $this->argument('domain');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! in_array($domain, ['treasury', 'pos'], true) || ! $limit || $limit < 1 || $limit > 500) {
            $this->error('Choose treasury or pos and a limit from 1 to 500.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('reporting_'.$domain.'_partitions')) {
            $this->info('Cash Reporting migration has not been applied.');

            return self::SUCCESS;
        }
        $recovery = new CashProjectionRecovery($domain);
        $recovery->scan($limit);
        $this->info('Dispatched '.$recovery->dispatch($limit).' '.$domain.' reporting jobs.');

        return self::SUCCESS;
    }
}
