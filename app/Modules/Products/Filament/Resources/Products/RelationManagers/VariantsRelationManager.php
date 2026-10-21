<?php

namespace App\Modules\Products\Filament\Resources\Products\RelationManagers;

use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\ProductInventorySummary;
use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Products\Schemas\ProductVariantFields;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Products\Services\ProductVariantManager;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Products\Services\UnallocatedVariantService;
use App\Modules\Products\Services\VariantInventoryValuation;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Reactive;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    /** @var array{unified: bool, shared: mixed}|null */
    #[Reactive]
    public ?array $pricingContext = null;

    public function usesUnifiedPrice(): bool
    {
        return $this->pricingContext['unified'] ?? (bool) $this->getOwnerRecord()->has_fixed_selling_price;
    }

    public function sharedSellingPrice(): mixed
    {
        return $this->pricingContext === null ? $this->getOwnerRecord()->sale_price : $this->pricingContext['shared'];
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Variants');
    }

    protected static function getModelLabel(): ?string
    {
        return __('Variant');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Sellable variant'))->description(__('SKU and barcode are generated automatically when the variant is created. Prices are shared across locations.'))->columns(2)->schema(ProductVariantFields::make()),
        ]);
    }

    public function table(Table $table): Table
    {
        $canViewValuation = app(VariantInventoryValuation::class)->canView(auth()->user())
            && auth()->user()->can('view', $this->getOwnerRecord());

        return $table->heading(__('Variants & prices'))->description(__('Manage sellable SKUs, size/color combinations, sale prices and reference costs.'))->emptyStateHeading(__('Add the first sellable variant'))->emptyStateDescription(__('Create a dimensionless variant for a simple product, or size/color variants for a variable product.'))->recordTitleAttribute('sku')->modifyQueryUsing(function (Builder $query): Builder {
            if (! app(ProductsAccess::class)->referenceCost(auth()->user())) {
                $query->select(['product_variants.id', 'product_variants.uuid', 'product_id', 'unallocated_product_id', 'size_id', 'color_id', 'sku', 'barcode', 'sale_price', 'is_active', 'created_at', 'updated_at']);
            }

            return $query->with(['product.images', 'images', 'size', 'color']);
        })->columns([
            TextColumn::make('sku')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record, 'title' => $state]))->label(__('SKU'))->searchable(), TextColumn::make('barcode')->label(__('Barcode'))->searchable()->placeholder(__('No barcode'))->toggleable(isToggledHiddenByDefault: true), TextColumn::make('size.name')->label(__('Size')), TextColumn::make('color.name')->label(__('Color')), TextInputColumn::make('sale_price')->label(__('Effective selling price'))
                ->state(fn (ProductVariant $record): mixed => $this->pricingContext === null
                    ? app(SellingPriceResolver::class)->resolve($record)
                    : ($this->usesUnifiedPrice() ? $this->sharedSellingPrice() : $record->sale_price))
                ->type('number')->step('0.0001')->rules(['required', 'numeric', 'min:0', 'decimal:0,4'])
                ->disabled(fn (ProductVariant $record): bool => $record->isUnallocated() || $this->usesUnifiedPrice() || ! auth()->user()->can('updateSalePrice', $record))
                ->updateStateUsing(fn (ProductVariant $record, mixed $state): string => ProductMutation::run(fn () => app(ProductVariantManager::class)->save(auth()->user(), $this->getOwnerRecord(), ['sale_price' => $state], $record))->sale_price), TextColumn::make('cost_price')->label(__('Reference cost'))->visible(fn (): bool => app(ProductsAccess::class)->referenceCost(auth()->user())), IconColumn::make('is_active')->label(__('Active'))->boolean(),
            TextColumn::make('estimated_profitability')->label(__('Estimated profitability'))->visible(fn (): bool => app(ProductsAccess::class)->referenceCost(auth()->user()))
                ->state('estimated')->formatStateUsing(fn (ProductVariant $record) => view('components.profitability-summary', ['profitability' => app(ProfitabilityCalculator::class)->preview(
                    $this->usesUnifiedPrice() ? $this->sharedSellingPrice() : $record->sale_price, $record->cost_price,
                ), 'source' => __('Reference unit cost; before configured discounts and taxes')])),
        ])->filters([TernaryFilter::make('is_active')->label(__('Active'))])->headerActions([
            Action::make('enableUnallocated')->label(__('Enable unallocated receiving'))->requiresConfirmation()
                ->visible(fn (): bool => app(ProductsAccess::class)->allows(auth()->user(), 'products.variants.create')
                    && ! $this->getOwnerRecord()->variants()->whereNotNull('unallocated_product_id')->exists())
                ->action(function (): void {
                    ProductMutation::run(fn () => app(UnallocatedVariantService::class)->ensure(auth()->user(), $this->getOwnerRecord()));
                    Notification::make()->success()->title(__('Unallocated receiving enabled'))->send();
                }),
            Action::make('productInventory')->label(__('Product Inventory'))->modalSubmitAction(false)->slideOver()->modalWidth('5xl')
                ->visible(fn (): bool => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::BALANCES))
                ->modalContent(fn () => view('components.product-inventory-summary', app(ProductInventorySummary::class)->forProduct(auth()->user(), $this->getOwnerRecord()))),
            CreateAction::make()->using(fn (array $data): ProductVariant => ProductMutation::run(fn () => app(ProductVariantManager::class)->save(auth()->user(), $this->getOwnerRecord(), $data), $this->getMountedActionSchema()?->getStatePath())),
        ])->recordActions([
            Action::make('barcodePreview')->label(__('Barcode preview'))->icon('heroicon-o-magnifying-glass-plus')
                ->visible(fn (ProductVariant $record): bool => ! $record->isUnallocated())
                ->authorize(fn (ProductVariant $record): bool => auth()->user()->can('view', $record) && auth()->user()->can('view', $this->getOwnerRecord()))
                ->modalWidth('5xl')->modalSubmitAction(false)->modalContent(fn (ProductVariant $record) => view('components.variant-barcode', ['variant' => $record, 'compact' => false])),
            Action::make('printBarcode')->label(__('Print barcode label'))->icon('heroicon-o-printer')
                ->visible(fn (ProductVariant $record): bool => ! $record->isUnallocated())
                ->authorize(fn (ProductVariant $record): bool => auth()->user()->can('view', $record) && auth()->user()->can('view', $this->getOwnerRecord()))
                ->url(fn (ProductVariant $record): string => route('products.variants.barcode-label', $record))->openUrlInNewTab(),
            Action::make('inventoryValuation')->label(__('Inventory Valuation'))->icon('heroicon-o-calculator')
                ->visible($canViewValuation)
                ->authorize(fn (ProductVariant $record): bool => (int) $record->product_id === (int) $this->getOwnerRecord()->id
                    && $canViewValuation)
                ->modalHeading(__('Inventory Valuation'))->slideOver()->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->modalContent(fn (ProductVariant $record) => view('components.variant-inventory-valuation-modal', ['variantId' => $record->id, 'productId' => $this->getOwnerRecord()->id])),
            EditAction::make()->mutateRecordDataUsing(function (array $data): array {
                if (! app(ProductsAccess::class)->referenceCost(auth()->user())) {
                    unset($data['cost_price']);
                }

                return $data;
            })
                ->using(fn (ProductVariant $record, array $data): ProductVariant => ProductMutation::run(fn () => app(ProductVariantManager::class)->save(auth()->user(), $this->getOwnerRecord(), $data, $record), $this->getMountedActionSchema()?->getStatePath())),
            DeleteAction::make()->using(fn (ProductVariant $record): bool => ProductMutation::run(fn () => app(ProductVariantManager::class)->delete(auth()->user(), $this->getOwnerRecord(), $record))),
        ]);
    }
}
