<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class PurchasingProjection extends CashProjection
{
    public string $domain = 'purchasing';

    protected array $keys = ['supplier_id', 'currency', 'event', 'status', 'variant_id'];

    protected array $amounts = PurchasingReportingEvidence::FIELDS;

    public function request(int $scope, string $date): void
    {
        parent::request($scope, $date);
        app(ReadModelCache::class)->invalidate('reporting-purchasing-current:'.$scope);
    }

    /** Serialize current-balance rebuilds across different days of the same location. */
    public function project(int $scope, string $date): bool
    {
        return DB::transaction(function () use ($scope, $date): bool {
            DB::table('locations')->where('id', $scope)->lockForUpdate()->first();

            return app(DailyProjectionState::class)->project($this->domain, $scope, $date, function () use ($scope, $date): void {
                DB::table('reporting_purchasing_daily')->where('location_id', $scope)->where('report_date', $date)->delete();
                foreach (app(PurchasingReportingEvidence::class)->daily($scope, $date) as $event => $query) {
                    $query->selectRaw('? AS event, ? AS location_id, ? AS report_date, ? AS projected_at', [$event, $scope, $date, now()]);
                    DB::table('reporting_purchasing_daily')->insertUsing([
                        'supplier_id', 'currency', 'status', 'variant_id', ...PurchasingReportingEvidence::FIELDS,
                        'event', 'location_id', 'report_date', 'projected_at',
                    ], $query);
                }
                DB::table('reporting_purchasing_balances')->where('location_id', $scope)->delete();
                $balances = app(PurchasingReportingEvidence::class)->balances($scope)
                    ->selectRaw('? AS location_id, ? AS projected_at', [$scope, now()]);
                DB::table('reporting_purchasing_balances')->insertUsing([
                    'supplier_id', 'currency', 'billed', 'paid', 'outstanding', 'advances', 'net_balance', 'missing', 'location_id', 'projected_at',
                ], $balances);
                app(ReadModelCache::class)->invalidate('reporting-purchasing-current:'.$scope);
            });
        }, 3);
    }

    protected function rows(int $scope, string $date): array
    {
        $rows = [];
        foreach (app(PurchasingReportingEvidence::class)->daily($scope, $date) as $event => $query) {
            foreach ($query->get() as $row) {
                $rows[] = (array) $row + ['event' => $event];
            }
        }

        return $rows;
    }

    public function matches(int $scope, string $date): bool
    {
        if (! parent::matches($scope, $date)) {
            return false;
        }
        $fields = ['supplier_id', 'currency', 'billed', 'paid', 'outstanding', 'advances', 'net_balance', 'missing'];
        $expected = app(PurchasingReportingEvidence::class)->balances($scope)->orderBy('supplier_id')->get();
        $stored = DB::table('reporting_purchasing_balances')->where('location_id', $scope)->orderBy('supplier_id')->get($fields);
        $normalize = function ($rows) use ($fields): array {
            return $rows->map(function ($row) use ($fields): array {
                $result = [];
                foreach ($fields as $field) {
                    $result[$field] = in_array($field, ['supplier_id', 'currency'], true)
                        ? (string) $row->{$field} : (string) BigDecimal::of((string) $row->{$field})->toScale(4);
                }

                return $result;
            })->all();
        };

        return $normalize($expected) === $normalize($stored);
    }
}
