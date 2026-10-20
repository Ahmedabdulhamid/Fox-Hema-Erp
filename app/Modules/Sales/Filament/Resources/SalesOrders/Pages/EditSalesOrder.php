<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Sales\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\Sales\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $service = app(SalesOrderService::class);
        $this->revision = $service->revision($this->record);
        $data['items'] = $service->formItems($this->record);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(SalesOrderService::class);
        $record = ProductMutation::run(fn () => $service->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->record = $record;
        $this->revision = $service->revision($record);
        $this->data['items'] = $service->formItems($record);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('saveDraft')->label(__('Save draft'))->icon('heroicon-o-document-arrow-down')->action('save'), ViewAction::make()->label(__('Back'))->icon('heroicon-o-arrow-uturn-left')->color('gray')];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
