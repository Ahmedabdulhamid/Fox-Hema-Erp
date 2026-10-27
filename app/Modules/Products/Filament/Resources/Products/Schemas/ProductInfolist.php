<?php

namespace App\Modules\Products\Filament\Resources\Products\Schemas;

use App\Models\MoneyFormatter;
use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use App\Modules\Products\Filament\MasterDetail;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Products\Services\SellingPriceResolver;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make(__('Product images'))->icon('heroicon-o-photo')->columnSpan(['default' => 1, 'lg' => 1])->extraAttributes(['class' => 'fox-product-gallery'])->schema([
                    ImageEntry::make('gallery')->hiddenLabel()->disk('public')->height(120)
                        ->state(fn (Product $record): array => $record->images()->where('disk', 'public')->whereNull('product_variant_id')->orderByDesc('is_primary')->orderBy('sort_order')->pluck('path')->all())
                        ->placeholder(__('No product images')),
                    RepeatableEntry::make('variant_images')->hiddenLabel()->state(fn (Product $record) => $record->images()->with(['variant.color', 'variant.size'])->where('disk', 'public')->whereNotNull('product_variant_id')->orderBy('sort_order')->get())->schema([
                        ImageEntry::make('path')->hiddenLabel()->disk('public')->height(120),
                        TextEntry::make('variant_label')->label(__('Variant'))->state(__('General product'))->formatStateUsing(fn ($record) => $record->variant ? view('components.variant-summary', ['variant' => $record->variant]) : __('General product')),
                    ]),
                ]),
                Section::make(__('Product overview'))->icon('heroicon-o-cube')->columns(2)->columnSpan(['default' => 1, 'lg' => 2])->extraAttributes(['class' => 'fox-record-section'])->schema([
                    TextEntry::make('name')->label(__('Product'))->weight('bold'),
                    TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state): string => __(ucfirst($state->value)))->color(fn ($state): string => match ($state->value) {
                        'active' => 'success', 'draft' => 'warning', default => 'gray'
                    }),
                    TextEntry::make('is_active')->label(__('Availability'))->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray')->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
                    TextEntry::make('brand.name')->label(__('Brand'))->placeholder(__('No brand')),
                    TextEntry::make('category.name')->label(__('Category'))->placeholder(__('No category')),
                    TextEntry::make('default_sku')->label(__('Catalog reference'))->placeholder(__('Not set'))->copyable(),
                    TextEntry::make('created_at')->label(__('Created at'))->dateTime()->placeholder(__('Not recorded')),
                    TextEntry::make('updated_at')->label(__('Updated at'))->dateTime()->placeholder(__('Not recorded')),
                ]),
            ]),
            Section::make(__('Prices & sellable variants'))->icon('heroicon-o-currency-dollar')->columnSpanFull()->schema([
                RepeatableEntry::make('variant_summary')->hiddenLabel()->extraAttributes(['class' => 'fox-master-detail-table'])->table([
                    TableColumn::make(__('SKU')), TableColumn::make(__('Variant')), TableColumn::make(__('Sale price')), TableColumn::make(__('View Details')),
                ])->state(function (Product $record): Collection {
                    $columns = ['id', 'product_id', 'sku', 'barcode', 'sale_price', 'size_id', 'color_id', 'unallocated_product_id'];
                    if (app(ProductsAccess::class)->referenceCost(auth()->user())) {
                        $columns[] = 'cost_price';
                    }

                    return $record->variants()->with(['size', 'color'])->get($columns)->each(fn (ProductVariant $variant) => $variant->setRelation('product', $record));
                })->schema([
                    TextEntry::make('sku')->label(__('SKU'))->copyable(),
                    View::make('components.variant-summary')->viewData(fn ($record): array => ['variant' => $record]),
                    TextEntry::make('sale_price')->label(__('Sale price'))->state(fn (ProductVariant $record) => app(SellingPriceResolver::class)->resolve($record))->formatStateUsing(fn ($state): string => MoneyFormatter::precise($state)),
                    MasterDetail::entry(fn (): array => self::variantDetails()),
                ]),
            ]),
            Section::make(__('Product description'))->icon('heroicon-o-document-text')->columnSpanFull()->schema([
                TextEntry::make('description')->hiddenLabel()->html()->placeholder(__('No description yet'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function variantDetails(): array
    {
        return [
            Section::make(__('Variant'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('sku')->label(__('SKU'))->copyable(),
                TextEntry::make('barcode')->label(__('Barcode'))->placeholder(__('No barcode'))->formatStateUsing(fn ($state) => view('components.barcode-preview', ['value' => $state])),
                TextEntry::make('size.name')->label(__('Size'))->placeholder(__('No size')),
                TextEntry::make('color.name')->label(__('Color'))->default(__('No color')),
            ]),
            Section::make(__('Prices & sellable variants'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('sale_price')->label(__('Sale price'))->state(fn (ProductVariant $record) => app(SellingPriceResolver::class)->resolve($record))->formatStateUsing(fn ($state): string => MoneyFormatter::precise($state)),
                TextEntry::make('cost_price')->label(__('Reference cost'))->formatStateUsing(fn ($state): string => MoneyFormatter::precise($state))->visible(fn (): bool => app(ProductsAccess::class)->referenceCost(auth()->user())),
                TextEntry::make('profitability')->label(__('Estimated profitability'))->columnSpanFull()->visible(fn (): bool => app(ProductsAccess::class)->referenceCost(auth()->user()))
                    ->state('estimated')->formatStateUsing(fn (ProductVariant $record) => view('components.profitability-summary', ['profitability' => app(ProfitabilityCalculator::class)->calculate(app(SellingPriceResolver::class)->resolve($record), $record->cost_price), 'source' => __('Reference unit cost; before configured discounts and taxes')])),
            ]),
        ];
    }
}
