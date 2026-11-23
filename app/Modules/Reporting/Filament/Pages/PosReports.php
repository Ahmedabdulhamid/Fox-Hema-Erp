<?php

namespace App\Modules\Reporting\Filament\Pages;

use App\Modules\Reporting\Services\PosReadReport;
use App\Modules\Reporting\Services\PosReportingAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class PosReports extends SalesReports
{
    use WithPagination;

    protected static string $translationKey = 'pos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-tablet';

    protected string $view = 'reporting::pos-reports';

    protected static ?string $slug = 'reporting/pos';

    public ?int $cashierId = null;

    public ?int $deviceId = null;

    public ?int $sessionId = null;

    public static function canAccess(): bool
    {
        return app(PosReportingAccess::class)->allows(auth()->user());
    }

    public function loadReport(): void
    {
        app(PosReadReport::class)->locations(auth()->user(), $this->from, $this->until, $this->locationId);
        $this->isLoaded = true;
    }

    public function applyFilters(): void
    {
        $this->validate(['cashierId' => 'nullable|integer|min:1', 'deviceId' => 'nullable|integer|min:1',
            'sessionId' => 'nullable|integer|min:1'], [], __('reporting.validation.attributes'));
        $this->resetPage();
        parent::applyFilters();
    }

    public function resetFilters(): void
    {
        $this->cashierId = null;
        $this->deviceId = null;
        $this->sessionId = null;
        $this->resetPage();
        parent::resetFilters();
    }

    #[Computed]
    public function branches(): array
    {
        return app(PosReportingAccess::class)->branches(auth()->user());
    }

    #[Computed]
    public function options(): array
    {
        $access = app(PosReportingAccess::class);
        $locations = array_keys($access->branches(auth()->user(), $this->locationId));
        $cashier = $access->cashier(auth()->user());
        $rows = DB::table('pos_sessions as s')->join('pos_devices as d', 'd.id', '=', 's.pos_device_id')->join('users as u', 'u.id', '=', 's.opened_by')
            ->whereIn('s.location_id', $locations)->when($cashier !== null, fn ($q) => $q->where('s.opened_by', $cashier))->orderByDesc('s.id')->limit(100)
            ->get(['s.id', 's.session_number', 's.opened_by', 's.pos_device_id', 'd.name as device', 'u.name as cashier']);

        return ['cashierId' => $rows->pluck('cashier', 'opened_by')->all(), 'deviceId' => $rows->pluck('device', 'pos_device_id')->all(), 'sessionId' => $rows->pluck('session_number', 'id')->all()];
    }

    #[Computed]
    public function report(): array
    {
        return app(PosReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency, $this->cashierId, $this->deviceId, $this->sessionId, $this->getPage());
    }

    /** @param list<array<string,mixed>> $rows */
    public function paginator(array $rows, int $total): LengthAwarePaginator
    {
        return new LengthAwarePaginator($rows, $total, 25, $this->getPage(), ['path' => request()->url()]);
    }
}
