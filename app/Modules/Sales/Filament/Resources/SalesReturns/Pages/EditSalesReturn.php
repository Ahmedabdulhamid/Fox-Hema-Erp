<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Modules\Sales\Filament\SalesMutation;
use App\Modules\Sales\Filament\SalesReturnActions;
use App\Modules\Sales\Services\SalesReturnService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditSalesReturn extends EditRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesReturnResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->revision = app(SalesReturnService::class)->revision($this->record);
        $data['items'] = $this->record->items()->orderBy('id')->get()->map(fn ($item) => $item->only(['id', 'sales_delivery_item_id', 'sales_invoice_item_id', 'quantity', 'disposition', 'notes']))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = SalesMutation::run(fn () => app(SalesReturnService::class)->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->revision = app(SalesReturnService::class)->revision($record);

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('returns.saved');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), SalesReturnActions::cancel()];
    }
}
