<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\PurchasingProjectionRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverPurchasingReports extends Command
{
    protected $signature = 'reporting:purchasing-recover {--limit=100}';

    protected $description = 'Bounded durable purchasing recovery; leaves existing failed jobs untouched';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 500) {
            $this->error('Choose a limit from 1 to 500.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('reporting_purchasing_partitions')) {
            $this->info('Purchasing reporting migration has not been applied.');

            return self::SUCCESS;
        }
        $recovery = app(PurchasingProjectionRecovery::class);
        $recovery->scan($limit);
        $this->info('Dispatched '.$recovery->dispatch($limit).' purchasing reporting jobs.');

        return self::SUCCESS;
    }
}
