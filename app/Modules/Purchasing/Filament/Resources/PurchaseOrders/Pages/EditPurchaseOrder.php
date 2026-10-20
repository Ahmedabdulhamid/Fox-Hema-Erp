<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->revision = app(PurchaseOrderService::class)->revision($this->record);
        $data['items'] = $this->record->items()->with('variant.product')->orderBy('id')->get()->map(fn ($item): array => ['brand_id' => $item->variant->product?->brand_id, 'discount_id' => $item->commercial_snapshot['discount']['id'] ?? null, 'tax_ids' => array_column($item->commercial_snapshot['taxes'] ?? [], 'id')] + app(PurchaseOrderProductSelection::class)->formState($item->variant) + Arr::only($item->toArray(), ['id', 'variant_id', 'quantity', 'unit_price', 'notes']))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = ProductMutation::run(fn () => app(PurchaseOrderService::class)->update(auth()->user(), $record, $data, $this->revision), 'data');
        $this->revision = app(PurchaseOrderService::class)->revision($record);
        $this->record = $record;
        $this->data['items'] = $record->items->map(fn ($item): array => ['brand_id' => $item->variant->product?->brand_id, 'discount_id' => $item->commercial_snapshot['discount']['id'] ?? null, 'tax_ids' => array_column($item->commercial_snapshot['taxes'] ?? [], 'id')] + app(PurchaseOrderProductSelection::class)->formState($item->variant) + Arr::only($item->toArray(), ['id', 'variant_id', 'quantity', 'unit_price', 'notes']))->all();

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
