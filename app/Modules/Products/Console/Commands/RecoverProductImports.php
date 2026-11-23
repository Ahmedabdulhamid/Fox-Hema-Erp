<?php

namespace App\Modules\Products\Console\Commands;

use App\Modules\Products\Services\ProductImportRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverProductImports extends Command
{
    protected $signature = 'products:recover-imports';

    protected $description = 'Recover interrupted product imports and reconcile expired private assets.';

    public function handle(ProductImportRecovery $recovery): int
    {
        if (! Schema::hasTable('product_imports')) {
            return self::SUCCESS;
        }
        $recovery->recover();

        return self::SUCCESS;
    }
}
