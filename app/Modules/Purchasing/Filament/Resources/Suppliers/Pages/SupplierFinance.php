<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Pages;

use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Services\SupplierFinancialOverview;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

class SupplierFinance extends ViewRecord
{
    use WithPagination;

    protected static string $resource = SupplierResource::class;

    protected string $view = 'purchasing::supplier-finance';

    public ?string $locationId = null;

    public ?string $from = null;

    public ?string $cutoff = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->cutoff = now()->toDateString();
    }

    public function getTitle(): string
    {
        return __('Supplier financial overview');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['locationId', 'from', 'cutoff'], true)) {
            $this->resetPage('statementPage');
        }
    }

    protected function getViewData(): array
    {
        try {
            $locationId = $this->locationId === null || $this->locationId === '' ? null : (int) $this->locationId;
            $data = app(SupplierFinancialOverview::class)->reportForDisplay(auth()->user(), (int) $this->record->id,
                $locationId, $this->cutoff ?: null, $this->from ?: null, $this->getPage('statementPage'), $this->record);

            return ['finance' => $data, 'financeError' => null];
        } catch (ValidationException $exception) {
            return ['finance' => null, 'financeError' => $exception->validator?->errors()->first() ?? collect($exception->errors())->flatten()->first()];
        }
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('supplier')->label(__('Supplier'))->url(SupplierResource::getUrl('view', ['record' => $this->record])),
            Action::make('newPayment')->label(__('Create new vendor payment'))->visible(fn (): bool => VendorPaymentResource::canCreate())->url(VendorPaymentResource::getUrl('create'))];
    }
}
