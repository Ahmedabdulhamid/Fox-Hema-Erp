<?php

namespace App\Modules\Products\Filament\Resources\Products\Schemas;

use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductsAccess;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;

class ProductVariantFields
{
    public static function make(bool $duringProductCreation = false): array
    {
        $access = app(ProductsAccess::class);
        $size = Select::make('size_id')->label(__('Size'))->searchable()->preload();
        $color = Select::make('color_id')->label(__('Color'))->searchable()->preload();
        if ($duringProductCreation) {
            $size->options(fn (): array => Size::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all());
            $color->options(fn (): array => Color::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all());
        } else {
            $size->relationship('size', 'name', modifyQueryUsing: fn ($query, ?ProductVariant $record) => $query->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $record?->size_id)));
            $color->relationship('color', 'name', modifyQueryUsing: fn ($query, ?ProductVariant $record) => $query->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $record?->color_id)));
        }
        $fields = [$size, $color];
        if (! $duringProductCreation) {
            $fields[] = TextEntry::make('identifier_sku')->label(__('SKU'))->state(fn (?ProductVariant $record): ?string => $record?->sku)->visible(fn (?ProductVariant $record): bool => $record?->exists ?? false)->copyable();
            $fields[] = TextEntry::make('identifier_barcode')->label(__('Barcode'))->formatStateUsing(fn ($state) => view('components.barcode-preview', ['value' => $state]))->state(fn (?ProductVariant $record): ?string => $record?->barcode)->visible(fn (?ProductVariant $record): bool => $record?->exists ?? false)->copyable();
        }
        $fixed = fn ($livewire, Get $get): bool => $duringProductCreation ? (bool) $get('../../has_fixed_selling_price') : $livewire->usesUnifiedPrice();
        $shared = fn ($livewire, Get $get): mixed => $duringProductCreation ? $get('../../sale_price') : $livewire->sharedSellingPrice();
        $fields[] = TextInput::make('sale_price')->label(__('Sale price'))->type('number')->inputMode('decimal')->minValue(0)->step('0.0001')
            ->rules(['numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'])
            ->default(fn ($livewire, Get $get): mixed => $shared($livewire, $get) ?? '0.0000')
            ->view('components.variant-selling-price', fn ($livewire, Get $get): array => ['unified' => $fixed($livewire, $get), 'sharedPrice' => $shared($livewire, $get)])
            ->disabled(fn ($livewire, Get $get): bool => ! $access->allows(auth()->user(), 'products.update_prices') || $fixed($livewire, $get))
            ->dehydrated(fn ($livewire, Get $get): bool => $access->allows(auth()->user(), 'products.update_prices') && ! $fixed($livewire, $get));
        $fields[] = Toggle::make('is_active')->label(__('Active'))->default(true);
        if ($access->referenceCost(auth()->user())) {
            $fields[] = TextInput::make('cost_price')->label(__('Reference cost (not accounting valuation)'))->numeric()->minValue(0)->step('0.0001')->disabled(! $access->allows(auth()->user(), 'products.update_reference_cost'))->dehydrated($access->allows(auth()->user(), 'products.update_reference_cost'));
        }

        return $fields;
    }
}
