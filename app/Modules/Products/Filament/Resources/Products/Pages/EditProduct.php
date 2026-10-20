<?php

namespace App\Modules\Products\Filament\Resources\Products\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Services\ProductAssignmentManager;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    use ProductAssignments;

    protected static string $resource = ProductResource::class;

    protected function getAllRelationManagers(): array
    {
        return array_map(fn ($manager) => $manager === VariantsRelationManager::class
            ? VariantsRelationManager::make(['pricingContext' => [
                'unified' => (bool) ($this->data['has_fixed_selling_price'] ?? $this->getRecord()->has_fixed_selling_price),
                'shared' => array_key_exists('sale_price', $this->data ?? []) ? $this->data['sale_price'] : $this->getRecord()->sale_price,
            ]])
            : $manager, parent::getAllRelationManagers());
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Product details');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCatalogManager::class)->save(auth()->user(), $record::class, $data, $record), 'data');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (['taxes' => 'tax_ids', 'discounts' => 'discount_ids'] as $kind => $field) {
            if (app(ProductAssignmentManager::class)->canAssign(auth()->user(), $kind)) {
                $data[$field] = $this->getRecord()->{$kind}()->pluck($kind.'.id')->all();
            }
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [...$this->assignmentActions(), ViewAction::make(), DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app(ProductCatalogManager::class)->delete(auth()->user(), $record)))];
    }
}
