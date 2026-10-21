<?php

namespace App\Modules\Sales\Filament\Pages;

use App\Modules\Sales\Services\ProfitabilityReport;
use App\Modules\Sales\Services\SalesAccess;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class Profitability extends Page
{
    use WithPagination;

    protected string $view = 'sales.profitability';

    protected static ?string $slug = 'sales-profitability';

    public string $from = '';

    public string $until = '';

    public ?int $locationId = null;

    public string $group = 'variant';

    public static function canAccess(): bool
    {
        return app(ProfitabilityReport::class)->canView(auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('Profitability');
    }

    public static function getNavigationGroup(): string
    {
        return __('Sales');
    }

    public function getTitle(): string
    {
        return __('Gross Profit & Margin');
    }

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->until = today()->toDateString();
    }

    public function applyFilters(): void
    {
        $this->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => 'required|in:product,variant,location,order', 'locationId' => 'nullable|integer|min:1']);
        $this->resetPage();
    }

    #[Computed]
    public function report(): array
    {
        $service = app(ProfitabilityReport::class);
        $events = $service->events(auth()->user(), $this->from, $this->until, $this->locationId);

        return ['summary' => $service->summary($events), 'rows' => $service->grouped($events, $this->group)->paginate(25)];
    }

    #[Computed]
    public function branches(): array
    {
        abort_unless(static::canAccess(), 403);

        return app(SalesAccess::class)->branches(auth()->user())->pluck('name', 'id')->all();
    }
}
