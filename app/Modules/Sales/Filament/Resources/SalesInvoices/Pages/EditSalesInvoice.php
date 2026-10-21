<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Modules\Sales\Filament\SalesInvoiceActions;
use App\Modules\Sales\Filament\SalesMutation;
use App\Modules\Sales\Services\SalesInvoiceService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditSalesInvoice extends EditRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesInvoiceResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->revision = app(SalesInvoiceService::class)->revision($this->record);
        $data['items'] = $this->record->items()->with('deliveryMatches')->orderBy('id')->get()->map(fn ($item): array => [
            'sales_delivery_item_id' => $item->deliveryMatches->first()?->sales_delivery_item_id, 'quantity' => $item->quantity,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = SalesMutation::run(fn () => app(SalesInvoiceService::class)->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->revision = app(SalesInvoiceService::class)->revision($record);

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('invoices.saved');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), SalesInvoiceActions::cancel()];
    }
}
