<?php

namespace App\Modules\Products\Filament\Resources\Brands\Tables;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
            IconColumn::make('is_active')->label(__('Active'))->boolean(),
            TextColumn::make('products_count')->label(__('Products'))->counts('products')])->filters([TernaryFilter::make('is_active')->label(__('Active'))])->defaultSort('name')->recordActions([
                ViewAction::make(), EditAction::make(), DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app(ProductCatalogManager::class)->delete(auth()->user(), $record))),
            ]);
    }
}
