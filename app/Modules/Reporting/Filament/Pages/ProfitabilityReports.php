<?php

namespace App\Modules\Reporting\Filament\Pages;

use App\Modules\Reporting\Services\ProfitabilityAccess;
use App\Modules\Reporting\Services\ProfitabilityReadReport;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class ProfitabilityReports extends SalesReports
{
    use WithPagination;

    protected static string $translationKey = 'profitability';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected string $view = 'reporting::profitability-reports';

    protected static ?string $slug = 'reporting/profitability';

    public string $group = 'variant';

    public static function canAccess(): bool
    {
        return app(ProfitabilityAccess::class)->allows(auth()->user());
    }

    public function loadReport(): void
    {
        app(ProfitabilityReadReport::class)->locations(auth()->user(), $this->from, $this->until, $this->locationId);
        $this->isLoaded = true;
    }

    public function applyFilters(): void
    {
        $this->validate(['group' => 'required|in:product,variant'], [], __('reporting.validation.attributes'));
        $this->resetPage();
        parent::applyFilters();
    }

    public function resetFilters(): void
    {
        $this->group = 'variant';
        $this->resetPage();
        parent::resetFilters();
    }

    #[Computed]
    public function branches(): array
    {
        return app(ProfitabilityAccess::class)->branches(auth()->user());
    }

    #[Computed]
    public function report(): array
    {
        return app(ProfitabilityReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency, $this->group, $this->getPage());
    }

    #[Computed]
    public function freshness(): array
    {
        return app(ProfitabilityReadReport::class)->freshness(auth()->user(), $this->from, $this->until, $this->locationId);
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $report = $this->report;

        return new LengthAwarePaginator($report['breakdown'], $report['total_rows'], $report['per_page'], $report['page'], ['path' => request()->url()]);
    }
}
