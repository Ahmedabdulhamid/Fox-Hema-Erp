<?php

namespace App\Modules\Payments\Filament\Pages;

use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\PaymentsPermissions;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class CustomerPaymentHistory extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    use WithPagination;

    protected string $view = 'payments::history';

    protected static ?string $slug = 'customer-payment-history';

    #[Url]
    public ?int $customer = null;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public string $kind = '';

    public static function getNavigationGroup(): ?string
    {
        return __('Payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('Customer payment history');
    }

    public function getTitle(): string
    {
        return $this->customer === null ? __('Customer payment history') : __('Customer statement');
    }

    public static function canAccess(): bool
    {
        return app(CustomerAccess::class)->allows(auth()->user(), PaymentsPermissions::VIEW);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['customer', 'search', 'from', 'until', 'kind'], true)) {
            $this->resetPage();
        }
    }

    protected function getViewData(): array
    {
        if (! app(CustomerAccess::class)->ready()) {
            return ['schemaReady' => false];
        }

        $customers = app(CustomerAccess::class)->scope(Customer::query(), auth()->user())->orderBy('name')->pluck('name', 'id');
        if ($this->customer !== null) {
            abort_unless($customers->has($this->customer), 403);
        }
        $this->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kind' => ['in:,receipt,refund,recovery,allocation,release,invoice,credit'], 'search' => ['string', 'max:191']], [], __('payments.validation_attributes'));

        return ['schemaReady' => true, 'customers' => $customers, 'rows' => app(CustomerFinancials::class)->history(auth()->user(), $this->customer, $this->from, $this->until, $this->search, $this->kind)->paginate(25),
            'balances' => $this->customer === null ? null : app(CustomerFinancials::class)->customer(auth()->user(), Customer::findOrFail($this->customer))];
    }
}
