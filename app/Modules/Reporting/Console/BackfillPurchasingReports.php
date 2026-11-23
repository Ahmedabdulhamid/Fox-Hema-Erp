<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\PurchasingProjection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class BackfillPurchasingReports extends Command
{
    protected $signature = 'reporting:purchasing-backfill {--location=} {--from=} {--until=} {--check : Read-only reconciliation; never requests or rebuilds projections}';

    protected $description = 'Rebuild or reconcile at most 31 purchasing location-days and current supplier balances';

    public function handle(): int
    {
        $data = ['location' => $this->option('location'), 'from' => $this->option('from'), 'until' => $this->option('until')];
        $validator = Validator::make($data, ['location' => 'required|integer|min:1', 'from' => 'required|date_format:Y-m-d',
            'until' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $scope = (int) $data['location'];
        $start = CarbonImmutable::parse($data['from']);
        $end = CarbonImmutable::parse($data['until']);
        if (! DB::table('locations')->where('id', $scope)->exists() || $start->diffInDays($end) > 30
            || ! Schema::hasTable('reporting_purchasing_partitions')) {
            $this->error('Apply the purchasing reporting migration and choose an existing location and at most 31 days.');

            return self::FAILURE;
        }
        $projection = app(PurchasingProjection::class);
        $differences = 0;
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            if ($this->option('check')) {
                $differences += (int) ! $projection->isCurrent($scope, $day->toDateString());
            } else {
                $projection->request($scope, $day->toDateString());
                $projection->project($scope, $day->toDateString());
            }
        }
        $this->info($this->option('check') ? 'Incomplete, pending or mismatched days: '.$differences : 'Purchasing reporting backfill complete.');

        return $differences > 0 ? self::FAILURE : self::SUCCESS;
    }
}
