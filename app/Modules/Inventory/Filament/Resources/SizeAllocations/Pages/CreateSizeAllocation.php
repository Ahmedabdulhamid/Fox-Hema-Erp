<?php

namespace App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages;

use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\SizeAllocationPresentation;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\Products\Filament\ProductMutation;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateSizeAllocation extends CreateRecord
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

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Save Draft'))->color('gray')->icon('heroicon-o-document-text')->disabled(fn (): bool => ! $this->allocationIsReady());
    }

    protected function getFormActions(): array
    {
        return [$this->getCancelFormAction(), $this->getCreateFormAction(),
            Action::make('confirmAllocation')->label(__('Confirm allocation'))->icon('heroicon-o-check')->requiresConfirmation()
                ->modalDescription(__('Save and post this allocation? Stock and permissions will be checked again.'))
                ->visible(fn (): bool => $this->canPostAllocation())->disabled(fn (): bool => ! $this->allocationIsReady())
                ->action('createAndPost')];
    }

    public function createAndPost(): void
    {
        $this->postImmediately = true;
        try {
            $this->create();
        } finally {
            $this->postImmediately = false;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->postImmediately ? __('Size allocation posted') : __('Allocation draft saved');
    }

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $submissionId;

    public function mount(): void
    {
        $this->submissionId = (string) Str::uuid7();
        parent::mount();
        $productId = request()->integer('product');
        $locationId = request()->integer('location');
        if ($productId && $locationId) {
            $product = SizeAllocationResource::eligibleProducts()->whereKey($productId)->first();
            if ($product !== null && app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::ALLOCATIONS_MANAGE, $locationId)) {
                $this->form->fill(['brand_id' => $product->brand_id ?? 'none', 'product_id' => $productId, 'location_id' => $locationId, 'items' => SizeAllocationResource::grid($productId)]);
            }
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => DB::transaction(function () use ($data): Model {
            $service = app(SizeAllocationService::class);
            $record = $service->create(auth()->user(), $data, $this->submissionId);

            return $this->postImmediately ? $service->post(auth()->user(), $record, $service->revision($record)) : $record;
        }), 'data');
    }
}
