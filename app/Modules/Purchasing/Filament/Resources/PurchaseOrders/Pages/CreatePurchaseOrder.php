<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

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
        return ProductMutation::run(fn () => app(PurchaseOrderService::class)->create(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Save draft'));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('saveDraft')->label(__('Save draft'))->icon('heroicon-o-document-arrow-down')->action('create'),
            Action::make('back')->label(__('Cancel'))->color('gray')->icon('heroicon-o-x-mark')->url(PurchaseOrderResource::getUrl('index'))];
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseOrderResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
