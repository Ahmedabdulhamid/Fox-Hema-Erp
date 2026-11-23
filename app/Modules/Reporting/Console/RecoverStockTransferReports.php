<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\StockTransferProjectionRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverStockTransferReports extends Command
{
    protected $signature = 'reporting:stock_transfer-recover {--limit=100}';

    protected $description = 'Bounded durable stock_transfer recovery; leaves existing failed jobs untouched';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 500) {
            $this->error('Choose a limit from 1 to 500.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('reporting_stock_transfer_partitions')) {
            $this->info('StockTransfer reporting migration has not been applied.');

            return self::SUCCESS;
        }
        $recovery = app(StockTransferProjectionRecovery::class);
        $recovery->scan($limit);
        $this->info('Dispatched '.$recovery->dispatch($limit).' stock_transfer reporting jobs.');

        return self::SUCCESS;
    }
}
