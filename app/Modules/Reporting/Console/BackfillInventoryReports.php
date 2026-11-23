<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\InventoryProjection;
use Illuminate\Support\Facades\Validator;

class BackfillInventoryReports extends BackfillSalesReports
{
    protected $signature = 'reporting:inventory-backfill {--location= : Location ID} {--from= : First movement date (Y-m-d)} {--until= : Last date (Y-m-d), maximum 31 days}';

    protected $description = 'Rebuild at most 31 inventory reporting days for one authorized location';

    protected string $projectionClass = InventoryProjection::class;

    protected bool $branchesOnly = false;

    public function handle(): int
    {
        if (Validator::make(['until' => $this->option('until')], ['until' => 'required|date_format:Y-m-d|before_or_equal:today'])->fails()) {
            $this->error('Choose a valid end date no later than today.');

            return self::FAILURE;
        }

        return parent::handle();
    }
}
