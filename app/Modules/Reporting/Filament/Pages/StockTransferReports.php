<?php

namespace App\Modules\Reporting\Filament\Pages;

use App\Models\QuantityFormatter;
use App\Modules\Reporting\Services\StockTransferReadReport;
use App\Modules\Reporting\Services\StockTransferReportingAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class StockTransferReports extends SalesReports
{
    use WithPagination;

    protected static string $translationKey = 'stock_transfers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected string $view = 'reporting::stock-transfer-reports';

    protected static ?string $slug = 'reporting/stock-transfers';

    public ?int $destinationId = null;

    public ?int $variantId = null;

    public static function canAccess(): bool
    {
        return app(StockTransferReportingAccess::class)->allows(auth()->user());
    }

    public function loadReport(): void
    {
        app(StockTransferReadReport::class)->locations(auth()->user(), $this->from, $this->until, $this->locationId);
        $this->isLoaded = true;
    }

    public function applyFilters(): void
    {
        $this->validate(['destinationId' => 'nullable|integer|min:1', 'variantId' => 'nullable|integer|min:1'], [], [
            'destinationId' => __('stock_transfer_reporting.destination'), 'variantId' => __('reporting.filters.variant'),
        ]);
        $this->resetPage();
        parent::applyFilters();
    }

    public function resetFilters(): void
    {
        $this->destinationId = null;
        $this->variantId = null;
        $this->resetPage();
        parent::resetFilters();
    }

    #[Computed]
    public function branches(): array
    {
        return app(StockTransferReportingAccess::class)->branches(auth()->user());
    }

    #[Computed]
    public function report(): array
    {
        return app(StockTransferReadReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId,
            destinationId: $this->destinationId, variantId: $this->variantId, page: $this->getPage());
    }

    /** @param array{items:list<array<string,mixed>>,total:int} $rows */
    public function paginator(array $rows): LengthAwarePaginator
    {
        return new LengthAwarePaginator($rows['items'], $rows['total'], 25, $this->getPage(), ['path' => request()->url()]);
    }

    public function quantity(mixed $value): string
    {
        return $value === null ? __('reporting.values.unavailable') : QuantityFormatter::format($value);
    }

    public function age(?string $date): string
    {
        $age = app(StockTransferReadReport::class)->age($date);

        return $age === null ? __('reporting.values.unavailable') : __('stock_transfer_reporting.days', ['count' => $age]);
    }
}
