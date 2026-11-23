<?php

namespace App\Modules\Reporting\Filament\Widgets;

use App\Modules\Reporting\Services\PosReadReport;
use App\Modules\Reporting\Services\PosReportingAccess;
use App\Modules\Reporting\Services\TreasuryReadReport;
use App\Modules\Reporting\Services\TreasuryReportingAccess;
use Livewire\Attributes\Locked;

class DailyCashChart extends DailySalesChart
{
    #[Locked]
    public string $domain = 'treasury';

    #[Locked]
    public ?int $accountId = null;

    #[Locked]
    public ?int $cashierId = null;

    #[Locked]
    public ?int $deviceId = null;

    #[Locked]
    public ?int $sessionId = null;

    public static function canView(): bool
    {
        return app(TreasuryReportingAccess::class)->allows(auth()->user()) || app(PosReportingAccess::class)->allows(auth()->user());
    }

    public function getHeading(): string
    {
        return $this->domain === 'treasury'
            ? __('reporting.charts.daily_treasury').' ('."\u{2068}".$this->currency."\u{2069}".')'
            : __('reporting.charts.daily_pos').' ('."\u{2068}".$this->currency."\u{2069}".')';
    }

    protected function getData(): array
    {
        abort_unless(in_array($this->domain, ['treasury', 'pos'], true), 422);
        $data = $this->domain === 'treasury'
            ? app(TreasuryReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->accountId, $this->currency)
            : app(PosReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency, $this->cashierId, $this->deviceId, $this->sessionId);

        return ['labels' => $this->formatLabels($data['daily']), 'datasets' => [[
            'label' => $this->getHeading(),
            'data' => array_map(fn (array $day): ?float => $day['net'] === null ? null : (float) $day['net'], $data['daily']),
            'borderColor' => $this->domain === 'treasury' ? '#07998c' : '#2563eb',
            'backgroundColor' => $this->domain === 'treasury' ? 'rgba(7, 153, 140, 0.12)' : 'rgba(37, 99, 235, 0.1)',
            'fill' => true,
            'tension' => 0.3,
            'spanGaps' => false,
        ]]];
    }
}
