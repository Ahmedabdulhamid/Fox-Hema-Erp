<?php

namespace App\Modules\Reporting\Filament\Pages;

use App\Modules\Reporting\Services\InventoryReadReport;
use App\Modules\Reporting\Services\InventoryReportingAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class InventoryReports extends SalesReports
{
    use WithPagination;

    protected static string $translationKey = 'inventory';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected string $view = 'reporting::inventory-reports';

    protected static ?string $slug = 'reporting/inventory';

    public static function canAccess(): bool
    {
        return app(InventoryReportingAccess::class)->allows(auth()->user());
    }

    public function loadReport(): void
    {
        app(InventoryReadReport::class)->locations(auth()->user(), $this->from, $this->until, $this->locationId);
        $this->isLoaded = true;
    }

    public function applyFilters(): void
    {
        foreach (['stockPage', 'movementPage', 'closingPage'] as $name) {
            $this->resetPage($name);
        }
        parent::applyFilters();
    }

    public function resetFilters(): void
    {
        foreach (['stockPage', 'movementPage', 'closingPage'] as $name) {
            $this->resetPage($name);
        }
        parent::resetFilters();
    }

    #[Computed]
    public function branches(): array
    {
        return app(InventoryReportingAccess::class)->branches(auth()->user());
    }

    #[Computed]
    public function report(): array
    {
        return app(InventoryReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, page: $this->getPage('stockPage'), movementPage: $this->getPage('movementPage'), closingPage: $this->getPage('closingPage'));
    }

    /** @param array<string,mixed> $data */
    public function paginator(array $data, string $name): LengthAwarePaginator
    {
        return new LengthAwarePaginator($data['items'], $data['total'], $data['per_page'], $data['page'], ['path' => request()->url(), 'pageName' => $name]);
    }
}
