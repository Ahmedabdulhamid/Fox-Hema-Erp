<?php

namespace App\Modules\Reporting\Filament\Widgets;

use App\Modules\Reporting\Services\PurchasingReadReport;
use App\Modules\Reporting\Services\PurchasingReportingAccess;
use Livewire\Attributes\Locked;

class DailyPurchasingChart extends DailySalesChart
{
    #[Locked]
    public ?int $supplierId = null;

    public static function canView(): bool
    {
        return app(PurchasingReportingAccess::class)->allows(auth()->user());
    }

    public function getHeading(): string
    {
        return __('purchasing_reporting.receipt_trend');
    }

    protected function getData(): array
    {
        $report = app(PurchasingReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency, $this->supplierId);

        return ['labels' => $this->formatLabels($report['daily']), 'datasets' => [[
            'label' => __('purchasing_reporting.receipt_quantity'),
            'data' => array_map(fn (array $day): ?float => $day['quantity'] === null ? null : (float) $day['quantity'], $report['daily']),
            'borderColor' => '#07998c', 'backgroundColor' => 'rgba(7, 153, 140, 0.12)',
            'fill' => true, 'tension' => 0.3, 'spanGaps' => false,
        ]]];
    }
}
