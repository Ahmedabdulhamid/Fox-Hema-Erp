<?php

namespace App\Modules\Products\Filament\Resources\Categories\Pages;

use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Categories\CategoryResource;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (app(CategoryCostingManager::class)->canView(auth()->user())) {
            $setting = $this->getRecord()->inventoryValuationSetting;
            $data['inventory_costing'] = ['costing_method' => $setting?->costing_method, 'note' => $setting?->note];
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCatalogManager::class)->save(auth()->user(), $record::class, $data, $record), 'data');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app(ProductCatalogManager::class)->delete(auth()->user(), $record)))];
    }
}
