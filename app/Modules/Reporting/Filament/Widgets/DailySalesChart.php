<?php

namespace App\Modules\Reporting\Filament\Widgets;

use App\Modules\Reporting\Services\ReportingAccess;
use App\Modules\Reporting\Services\SalesReport;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;

class DailySalesChart extends ChartWidget
{
    #[Locked]
    public string $from = '';

    #[Locked]
    public string $until = '';

    #[Locked]
    public ?int $locationId = null;

    #[Locked]
    public string $currency = 'EGP';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '320px';

    public static function canView(): bool
    {
        return app(ReportingAccess::class)->allows(auth()->user());
    }

    public function getHeading(): string
    {
        return __('reporting.charts.daily_sales').' ('."\u{2068}".$this->currency."\u{2069}".')';
    }

    protected function getData(): array
    {
        $report = app(SalesReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency);

        return ['labels' => $this->formatLabels($report['daily']), 'datasets' => [[
            'label' => __('reporting.charts.net_revenue'),
            'data' => array_map(fn (array $day): float => (float) $day['net_revenue'], $report['daily']),
            'borderColor' => '#07998c',
            'backgroundColor' => 'rgba(7, 153, 140, 0.12)',
            'fill' => true,
            'tension' => 0.3,
        ]]];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @param list<array{date: string}> $days
     * @return list<string>
     */
    protected function formatLabels(array $days): array
    {
        return array_map(fn (array $day): string => CarbonImmutable::parse($day['date'])
            ->locale(app()->getLocale())
            ->translatedFormat('d M Y'), $days);
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
