<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Services\CashProjection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class BackfillCashReports extends Command
{
    protected $signature = 'reporting:cash-backfill {domain : treasury or pos} {--account= : Treasury account ID} {--location= : POS branch ID} {--from=} {--until=} {--check : Reconcile without rebuilding}';

    protected $description = 'Rebuild or reconcile at most 31 treasury account-days or POS branch-days';

    public function handle(): int
    {
        $data = ['domain' => $this->argument('domain'), 'from' => $this->option('from'), 'until' => $this->option('until')];
        $validator = Validator::make($data, ['domain' => 'required|in:treasury,pos', 'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $projection = CashProjection::forDomain($data['domain']);
        $scope = filter_var($this->option($data['domain'] === 'treasury' ? 'account' : 'location'), FILTER_VALIDATE_INT);
        $query = DB::table($data['domain'] === 'treasury' ? 'treasury_accounts' : 'locations')->where('id', $scope);
        if ($data['domain'] === 'pos') {
            $query->where('type', 'branch');
        }
        $start = CarbonImmutable::parse($data['from']);
        $end = CarbonImmutable::parse($data['until']);
        if (! $scope || ! $query->exists() || $start->diffInDays($end) > 30 || ! Schema::hasTable('reporting_'.$data['domain'].'_partitions')) {
            $this->error('Apply this domain migration and choose an existing scope and at most 31 days.');

            return self::FAILURE;
        }
        $differences = 0;
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            if ($this->option('check')) {
                $differences += (int) ! $projection->isCurrent($scope, $date->toDateString());
            } else {
                $projection->request($scope, $date->toDateString());
                $projection->project($scope, $date->toDateString());
            }
        }
        $this->info($this->option('check') ? 'Incomplete, pending or mismatched days: '.$differences : 'Cash Reporting backfill complete.');

        return $differences > 0 ? self::FAILURE : self::SUCCESS;
    }
}
