<?php

namespace App\Modules\Reporting\Filament\Widgets;

use App\Modules\Reporting\Services\StockTransferReadReport;
use App\Modules\Reporting\Services\StockTransferReportingAccess;
use Livewire\Attributes\Locked;

class DailyStockTransferChart extends DailySalesChart
{
    protected ?string $maxHeight = '240px';

    #[Locked]
    public ?int $destinationId = null;

    #[Locked]
    public bool $transferCounts = false;

    public static function canView(): bool
    {
        return app(StockTransferReportingAccess::class)->allows(auth()->user());
    }

    public function getHeading(): string
    {
        return __('stock_transfer_reporting.'.($this->transferCounts ? 'transfer_trend' : 'trend'));
    }

    protected function getData(): array
    {
        $report = app(StockTransferReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, destinationId: $this->destinationId);
        $datasets = [];
        foreach (($this->transferCounts ? ['transfer' => '#6366f1'] : ['request_line' => '#6366f1', 'dispatch_line' => '#d97706', 'receipt_line' => '#07998c']) as $event => $color) {
            $datasets[] = [
                'label' => __('stock_transfer_reporting.events.'.$event),
                'data' => array_map(fn (array $day): ?float => $day[$event] === null ? null : (float) $day[$event], $report['daily']),
                'borderColor' => $color, 'backgroundColor' => $color, 'fill' => false, 'tension' => 0.3, 'spanGaps' => false,
            ];
        }

        return ['labels' => $this->formatLabels($report['daily']), 'datasets' => $datasets];
    }
}
