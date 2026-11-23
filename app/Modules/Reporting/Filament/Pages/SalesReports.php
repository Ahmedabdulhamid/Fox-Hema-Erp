<?php

namespace App\Modules\Reporting\Filament\Pages;

use App\Modules\Reporting\Services\ReportingAccess;
use App\Modules\Reporting\Services\SalesReport;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class SalesReports extends Page
{
    protected static string $translationKey = 'sales';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected string $view = 'reporting::sales-reports';

    protected static ?string $slug = 'reporting/sales';

    public string $from = '';

    public string $until = '';

    public ?int $locationId = null;

    public string $currency = 'EGP';

    #[Locked]
    public bool $isLoaded = false;

    public static function canAccess(): bool
    {
        return app(ReportingAccess::class)->allows(auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('reporting.pages.'.static::$translationKey.'.title');
    }

    public static function getNavigationGroup(): string
    {
        return __('reporting.navigation.group');
    }

    public function getTitle(): string
    {
        return __('reporting.pages.'.static::$translationKey.'.title');
    }

    public function getSubheading(): string
    {
        return __('reporting.pages.'.static::$translationKey.'.description');
    }

    public function getHeading(): HtmlString
    {
        return new HtmlString(view('reporting::components.page-heading', [
            'icon' => static::$navigationIcon,
            'title' => $this->getTitle(),
        ])->render());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->from = today()->startOfMonth()->toDateString();
        $this->until = today()->toDateString();
    }

    public function loadReport(): void
    {
        app(SalesReport::class)->locations(auth()->user(), $this->from, $this->until, $this->locationId);
        $this->isLoaded = true;
    }

    public function applyFilters(): void
    {
        $this->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from',
            'locationId' => 'nullable|integer|min:1', 'currency' => ['required', 'regex:/^[A-Z]{3}$/D']],
            [], __('reporting.validation.attributes'));
        $this->loadReport();
    }

    public function resetFilters(): void
    {
        $this->resetValidation();
        $this->from = today()->startOfMonth()->toDateString();
        $this->until = today()->toDateString();
        $this->locationId = null;
        $this->currency = 'EGP';
        $this->loadReport();
    }

    #[Computed]
    public function branches(): array
    {
        return app(ReportingAccess::class)->branches(auth()->user());
    }

    #[Computed]
    public function report(): array
    {
        return app(SalesReport::class)->read(auth()->user(), $this->from, $this->until, $this->locationId, $this->currency);
    }

    #[Computed]
    public function freshness(): array
    {
        return app(SalesReport::class)->freshness(auth()->user(), $this->from, $this->until, $this->locationId);
    }
}
