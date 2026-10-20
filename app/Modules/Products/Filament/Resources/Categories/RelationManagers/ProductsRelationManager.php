<?php

namespace App\Modules\Products\Filament\Resources\Categories\RelationManagers;

use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Products');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('view', $ownerRecord) ?? false) && ProductResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table->heading(__('Products in this category'))->description(__('Open a product to manage its variants, prices and images.'))->columns([
            TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
            TextColumn::make('brand.name')->label(__('Brand')),
            TextColumn::make('variants_count')->counts('variants')->label(__('Variants')),
            TextColumn::make('status')->label(__('Status'))->formatStateUsing(fn ($state): string => __(ucfirst($state->value)))->badge()->color(fn ($state): string => match ($state->value) {
                'active' => 'success', 'draft' => 'warning', default => 'gray'
            }),
            IconColumn::make('is_active')->label(__('Active'))->boolean(),
        ])->recordUrl(fn (Product $record): ?string => ProductResource::canView($record) ? ProductResource::getUrl('view', ['record' => $record]) : null)->emptyStateHeading(__('No products assigned'))->emptyStateDescription(__('Choose this category from a product to include it here.'));
    }
}
