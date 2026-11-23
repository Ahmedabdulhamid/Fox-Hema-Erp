<?php

namespace App\Modules\Reporting\Filament\Pages;

use App\Modules\Reporting\Services\PurchasingReadReport;
use App\Modules\Reporting\Services\PurchasingReportingAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class PurchasingReports extends SalesReports
{
    use WithPagination;

    protected static string $translationKey = 'purchasing';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected string $view = 'reporting::purchasing-reports';

    protected static ?string $slug = 'reporting/purchasing';

    public ?int $supplierId = null;

    public string $supplierSearch = '';

    public ?int $variantId = null;

    public static function canAccess(): bool
    {
        return app(PurchasingReportingAccess::class)->allows(auth()->user());
    }

    public function loadReport(): void
    {
        app(PurchasingReadReport::class)->locations(auth()->user(), $this->from, $this->until, $this->locationId);
        $this->isLoaded = true;
    }

    public function applyFilters(): void
    {
        $this->validate(['supplierId' => 'nullable|integer|min:1', 'variantId' => 'nullable|integer|min:1'], [], [
            'supplierId' => __('purchasing_reporting.supplier'), 'variantId' => __('reporting.filters.variant'),
        ]);
        $this->resetPage();
        parent::applyFilters();
    }

    public function resetFilters(): void
    {
        $this->supplierId = null;
        $this->supplierSearch = '';
        $this->variantId = null;
        $this->resetPage();
        parent::resetFilters();
    }

    #[Computed]
    public function branches(): array
    {
        return app(PurchasingReportingAccess::class)->branches(auth()->user());
    }

    #[Computed]
    public function suppliers(): array
    {
        app(PurchasingReportingAccess::class)->authorize(auth()->user());

        return DB::table('suppliers')->where(fn ($query) => $query
            ->where('name', 'like', '%'.mb_substr($this->supplierSearch, 0, 100).'%')
            ->when($this->supplierId !== null, fn ($query) => $query->orWhere('id', $this->supplierId)))
            ->orderBy('name')->limit(100)->pluck('name', 'id')->all();
    }

    #[Computed]
    public function report(): array
    {
        return app(PurchasingReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency, $this->supplierId, $this->variantId, $this->getPage());
    }

    /** @param list<array<string,mixed>> $rows */
    public function paginator(array $rows, int $total): LengthAwarePaginator
    {
        return new LengthAwarePaginator($rows, $total, 25, $this->getPage(), ['path' => request()->url()]);
    }
}
