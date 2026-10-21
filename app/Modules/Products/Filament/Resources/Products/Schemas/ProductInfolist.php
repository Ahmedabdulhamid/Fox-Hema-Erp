<?php

namespace App\Modules\Products\Filament\Resources\Products\Schemas;

use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Products\Services\SellingPriceResolver;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                    RepeatableEntry::make('variant_images')->hiddenLabel()->state(fn (Product $record): array => $record->images()->with(['variant.color', 'variant.size'])->where('disk', 'public')->whereNotNull('product_variant_id')->orderBy('sort_order')->get()->map(fn ($image): array => ['path' => $image->path, 'variant_label' => $image->variant?->imageLabel()])->all())->schema([
                        ImageEntry::make('path')->hiddenLabel()->disk('public')->height(120),
                        TextEntry::make('variant_label')->label(__('Variant')),
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
                RepeatableEntry::make('variant_summary')->hiddenLabel()->table(fn (): array => [
                    TableColumn::make(__('SKU')),
                    TableColumn::make(__('Sale price')),
                    ...(app(ProductsAccess::class)->referenceCost(auth()->user()) ? [TableColumn::make(__('Reference cost')), TableColumn::make(__('Estimated profitability'))] : []),
                    TableColumn::make(__('Barcode')),
                    TableColumn::make(__('Size')),
                    TableColumn::make(__('Color')),
                ])->state(function (Product $record): array {
                    $columns = ['sku', 'barcode', 'sale_price', 'size_id', 'color_id'];
                    if (app(ProductsAccess::class)->referenceCost(auth()->user())) {
                        $columns[] = 'cost_price';
                    }

                    return $record->variants()->with(['size', 'color'])->get($columns)->map(function ($variant) use ($record): array {
                        $variant->setRelation('product', $record);
                        $data = $variant->toArray();
                        $data['sale_price'] = app(SellingPriceResolver::class)->resolve($variant);
                        if (array_key_exists('cost_price', $data)) {
                            $data['profitability'] = app(ProfitabilityCalculator::class)->calculate($data['sale_price'], $variant->cost_price);
                        }

                        return $data;
                    })->all();
                })->schema([
                    TextEntry::make('sku')->label(__('SKU'))->copyable(),
                    TextEntry::make('sale_price')->label(__('Sale price'))->numeric(decimalPlaces: 4),
                    TextEntry::make('cost_price')->label(__('Reference cost'))->numeric(decimalPlaces: 4)->visible(fn (): bool => app(ProductsAccess::class)->referenceCost(auth()->user())),
                    TextEntry::make('profitability')->label(__('Estimated profitability'))->visible(fn (): bool => app(ProductsAccess::class)->referenceCost(auth()->user()))
                        ->state('estimated')->formatStateUsing(fn (TextEntry $component) => view('components.profitability-summary', ['profitability' => data_get($component->getContainer()->getConstantState(), 'profitability', []), 'source' => __('Reference unit cost; before configured discounts and taxes')])),
                    TextEntry::make('barcode')->label(__('Barcode'))->placeholder(__('No barcode'))
                        ->formatStateUsing(fn ($state) => view('components.barcode-preview', ['value' => $state])),
                    TextEntry::make('size.name')->label(__('Size'))->placeholder(__('No size')),
                    TextEntry::make('color.name')->label(__('Color'))->placeholder(__('No color')),
                ]),
            ]),
            Section::make(__('Product description'))->icon('heroicon-o-document-text')->columnSpanFull()->schema([
                TextEntry::make('description')->hiddenLabel()->html()->placeholder(__('No description yet'))->columnSpanFull(),
            ]),
        ]);
    }
}
