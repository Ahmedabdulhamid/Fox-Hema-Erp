<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class StockTransferProjection extends CashProjection
{
    public string $domain = 'stock_transfer';

    protected array $keys = ['to_location_id', 'event', 'status', 'variant_id'];

    protected array $amounts = StockTransferReportingEvidence::FIELDS;

    public function request(int $scope, string $date): void
    {
        parent::request($scope, $date);
        app(ReadModelCache::class)->invalidate('reporting-stock-transfer-current:'.$scope);
    }

    public function project(int $scope, string $date): bool
    {
        return DB::transaction(function () use ($scope, $date): bool {
            DB::table('locations')->where('id', $scope)->lockForUpdate()->first();

            return app(DailyProjectionState::class)->project($this->domain, $scope, $date, function () use ($scope, $date): void {
                DB::table('reporting_stock_transfer_daily')->where('location_id', $scope)->where('report_date', $date)->delete();
                foreach (app(StockTransferReportingEvidence::class)->daily($scope, $date) as $event => $query) {
                    $query->selectRaw('? AS event,? AS location_id,? AS report_date,? AS projected_at', [$event, $scope, $date, now()]);
                    DB::table('reporting_stock_transfer_daily')->insertUsing([
                        'to_location_id', 'status', 'variant_id', ...StockTransferReportingEvidence::FIELDS, 'event', 'location_id', 'report_date', 'projected_at',
                    ], $query);
                }
                DB::table('reporting_stock_transfer_transit')->where('location_id', $scope)->delete();
                DB::table('reporting_stock_transfer_transit')->insertUsing([
                    'shipment_item_id', 'to_location_id', 'transfer_id', 'shipment_id', 'variant_id', 'dispatched_at',
                    'dispatched', 'received', 'remaining', 'damaged', 'missing', 'reversals', 'location_id', 'projected_at',
                ], app(StockTransferReportingEvidence::class)->transit($scope)->selectRaw('? AS location_id,? AS projected_at', [$scope, now()]));
                app(ReadModelCache::class)->invalidate('reporting-stock-transfer-current:'.$scope);
            });
        }, 3);
    }

    protected function rows(int $scope, string $date): array
    {
        $rows = [];
        foreach (app(StockTransferReportingEvidence::class)->daily($scope, $date) as $event => $query) {
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
        $fields = ['shipment_item_id', 'to_location_id', 'transfer_id', 'shipment_id', 'variant_id', 'dispatched_at',
            'dispatched', 'received', 'remaining', 'damaged', 'missing', 'reversals'];
        $normalize = function ($rows) use ($fields): array {
            return $rows->map(function ($row) use ($fields): array {
                $result = [];
                foreach ($fields as $field) {
                    $result[$field] = in_array($field, ['dispatched', 'received', 'remaining', 'damaged', 'missing', 'reversals'], true)
                        ? (string) BigDecimal::of((string) $row->{$field})->toScale(4) : $row->{$field};
                }

                return $result;
            })->all();
        };

        return $normalize(app(StockTransferReportingEvidence::class)->transit($scope)->orderBy('si.id')->get())
            === $normalize(DB::table('reporting_stock_transfer_transit')->where('location_id', $scope)->orderBy('shipment_item_id')->get($fields));
    }
}
