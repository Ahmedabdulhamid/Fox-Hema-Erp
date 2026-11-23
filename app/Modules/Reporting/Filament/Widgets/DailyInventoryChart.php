<?php

namespace App\Modules\Reporting\Filament\Widgets;

use App\Modules\Reporting\Services\InventoryReadReport;
use App\Modules\Reporting\Services\InventoryReportingAccess;

class DailyInventoryChart extends DailySalesChart
{
    public static function canView(): bool
    {
        return app(InventoryReportingAccess::class)->allows(auth()->user());
    }

    public function getHeading(): string
    {
        return __('reporting.charts.daily_inventory');
    }

    protected function getData(): array
    {
        $trend = app(InventoryReadReport::class)->trend(auth()->user(), $this->from, $this->until, $this->locationId);

        return ['labels' => $this->formatLabels($trend['daily']), 'datasets' => [
            ['label' => __('reporting.charts.received'), 'data' => array_map(fn (array $day): float => (float) $day['received'], $trend['daily']), 'backgroundColor' => '#07998c'],
            ['label' => __('reporting.charts.issued'), 'data' => array_map(fn (array $day): float => (float) $day['issued'], $trend['daily']), 'backgroundColor' => '#dc4c64'],
        ]];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
