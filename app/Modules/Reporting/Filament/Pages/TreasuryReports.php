<?php

namespace App\Modules\Reporting\Filament\Pages;

use App\Modules\Reporting\Services\TreasuryReadReport;
use App\Modules\Reporting\Services\TreasuryReportingAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class TreasuryReports extends SalesReports
{
    use WithPagination;

    protected static string $translationKey = 'treasury';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected string $view = 'reporting::treasury-reports';

    protected static ?string $slug = 'reporting/treasury';

    public ?int $accountId = null;

    public static function canAccess(): bool
    {
        return app(TreasuryReportingAccess::class)->allows(auth()->user());
    }

    public function loadReport(): void
    {
        app(TreasuryReportingAccess::class)->accounts(auth()->user(), $this->locationId, $this->accountId);
        TreasuryReadReport::dates($this->from, $this->until);
        $this->isLoaded = true;
    }

    public function applyFilters(): void
    {
        $this->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from',
            'locationId' => 'nullable|integer|min:0', 'accountId' => 'nullable|integer|min:1',
            'currency' => 'required|regex:/^[A-Z]{3}$/D'], [], __('reporting.validation.attributes'));
        $this->resetPage();
        $this->loadReport();
    }

    public function resetFilters(): void
    {
        $this->accountId = null;
        $this->resetPage();
        parent::resetFilters();
    }

    #[Computed]
    public function branches(): array
    {
        return app(TreasuryReportingAccess::class)->branches(auth()->user());
    }

    #[Computed]
    public function accounts(): array
    {
        return app(TreasuryReportingAccess::class)->accounts(auth()->user(), $this->locationId);
    }

    #[Computed]
    public function report(): array
    {
        return app(TreasuryReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->accountId, $this->currency, $this->getPage());
    }

    /** @param list<array<string,mixed>> $rows */
    public function paginator(array $rows, int $total): LengthAwarePaginator
    {
        return new LengthAwarePaginator($rows, $total, 25, $this->getPage(), ['path' => request()->url()]);
    }
}
