<?php

namespace App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditSalesCreditNote extends EditRecord
{
    protected static string $resource = SalesCreditNoteResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->revision = app(SalesCreditNoteService::class)->revision($this->record);
        $data['items'] = $this->record->items()->orderBy('sales_return_posted_allocation_id')->get()->map(fn ($item): array => [
            'sales_return_posted_allocation_id' => $item->sales_return_posted_allocation_id, 'quantity' => $item->quantity,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = ProductMutation::run(fn () => app(SalesCreditNoteService::class)->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->revision = app(SalesCreditNoteService::class)->revision($record);

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('credits.saved');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
