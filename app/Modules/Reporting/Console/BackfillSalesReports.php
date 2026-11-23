<?php

namespace App\Modules\Reporting\Console;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Reporting\Services\SalesProjection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BackfillSalesReports extends Command
{
    protected $signature = 'reporting:sales-backfill {--location= : Branch ID} {--from= : First document date (Y-m-d)} {--until= : Last document date (Y-m-d), maximum 31 days}';

    protected $description = 'Rebuild at most 31 sales reporting days for one branch; safe to resume or repeat';

    protected string $projectionClass = SalesProjection::class;

    protected bool $branchesOnly = true;

    public function handle(): int
    {
        $projection = app($this->projectionClass);
        try {
            $input = Validator::make($this->options(), [
                'location' => ['required', 'integer', 'min:1'],
                'from' => ['required', 'date_format:Y-m-d'],
                'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            ])->validate();
            $start = CarbonImmutable::parse($input['from']);
            $end = CarbonImmutable::parse($input['until']);
            if ($start->diffInDays($end) > 30 || ! Location::whereKey($input['location'])->when($this->branchesOnly, fn ($query) => $query->where('type', 'branch'))->exists()) {
                $this->error('Choose an existing branch and a range of at most 31 days.');

                return self::FAILURE;
            }
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $projection->request((int) $input['location'], $date->toDateString());
            $projection->project((int) $input['location'], $date->toDateString());
        }
        $this->info('Reporting backfill complete.');

        return self::SUCCESS;
    }
}
