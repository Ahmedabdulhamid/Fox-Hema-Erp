<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Sales\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\Sales\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $submissionId;

    public function mount(): void
    {
        $this->submissionId = (string) Str::uuid7();
        parent::mount();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(SalesOrderService::class)->create(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Save draft'));
    }

    protected function getRedirectUrl(): string
    {
        return SalesOrderResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('saveDraft')->label(__('Save draft'))->icon('heroicon-o-document-arrow-down')->action('create'),
            Action::make('back')->label(__('Cancel'))->color('gray')->icon('heroicon-o-x-mark')->url(SalesOrderResource::getUrl('index'))];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
