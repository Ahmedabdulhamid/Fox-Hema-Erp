<?php

namespace App\Modules\Reporting\Filament\Widgets;

use App\Modules\Reporting\Services\ProfitabilityAccess;
use App\Modules\Reporting\Services\ProfitabilityReadReport;

class DailyProfitabilityChart extends DailySalesChart
{
    public static function canView(): bool
    {
        return app(ProfitabilityAccess::class)->allows(auth()->user());
    }

    public function getHeading(): string
    {
        return __('reporting.charts.daily_profit').' ('."\u{2068}".$this->currency."\u{2069}".')';
    }

    protected function getData(): array
    {
        $report = app(ProfitabilityReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency);

        return ['labels' => $this->formatLabels($report['daily']), 'datasets' => [[
            'label' => __('reporting.charts.gross_profit'),
            'data' => array_map(fn (array $day): ?float => $day['profit'] === null ? null : (float) $day['profit'], $report['daily']),
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
            'fill' => true,
            'tension' => 0.3,
            'spanGaps' => false,
        ]]];
    }
}
