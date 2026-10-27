<?php

namespace App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages;

use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\SizeAllocationPresentation;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\Products\Filament\ProductMutation;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class EditSizeAllocation extends EditRecord
{
    protected static string $resource = SizeAllocationResource::class;

    protected bool $postImmediately = false;

    public function getTitle(): string
    {
        return __('Size Allocation');
    }

    public function getSubheading(): ?string
    {
        return __('Distribute received unallocated stock to existing size variants.');
    }

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'allocation-page', 'fox-inventory-page'];
    }

    protected function allocationIsReady(): bool
    {
        return app(SizeAllocationPresentation::class)->context(auth()->user(), $this->data ?? [], $this->record ?? null)['is_valid'];
    }

    protected function canPostAllocation(): bool
    {
        $location = (int) ($this->data['location_id'] ?? 0);

        return $location > 0 && app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::ALLOCATIONS_POST, $location)
            && app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::POST, $location);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('Save Draft'))->color('gray')->icon('heroicon-o-document-text')->disabled(fn (): bool => ! $this->allocationIsReady());
    }

    protected function getFormActions(): array
    {
        return [$this->getCancelFormAction(), $this->getSaveFormAction(),
            Action::make('confirmAllocation')->label(__('Confirm allocation'))->icon('heroicon-o-check')->requiresConfirmation()
                ->modalDescription(__('Save and post this allocation? Stock and permissions will be checked again.'))
                ->visible(fn (): bool => $this->canPostAllocation())->disabled(fn (): bool => ! $this->allocationIsReady())
                ->action('saveAndPost')];
    }

    public function saveAndPost(): void
    {
        $this->postImmediately = true;
        try {
            $this->save(shouldRedirect: false);
            if ($this->record->status === 'posted') {
                $this->redirect(SizeAllocationResource::getUrl('view', ['record' => $this->record]));
            }
        } finally {
            $this->postImmediately = false;
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return $this->postImmediately ? __('Size allocation posted') : __('Allocation draft saved');
    }

    #[Locked]
    public string $revision;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->revision = app(SizeAllocationService::class)->revision($this->record);
        $data['items'] = $this->record->items()->orderBy('variant_id')->get(['variant_id', 'quantity'])->toArray();
        $data['brand_id'] = $this->record->product->brand_id ?? 'none';
        $labels = collect(app(SizeAllocationPresentation::class)->grid(auth()->user(), $this->record->product_id, record: $this->record))->keyBy('variant_id');
        $data['items'] = array_map(fn (array $item): array => $item + ($labels[$item['variant_id']] ?? []), $data['items']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = ProductMutation::run(fn () => DB::transaction(function () use ($record, $data): Model {
            $service = app(SizeAllocationService::class);
            $updated = $service->update(auth()->user(), $record, $data, $this->revision);

            return $this->postImmediately ? $service->post(auth()->user(), $updated, $service->revision($updated)) : $updated;
        }), 'data');
        $this->revision = app(SizeAllocationService::class)->revision($record);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
