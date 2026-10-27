<?php

namespace App\Modules\Products\Filament\Resources\Products\Tables;

use App\Models\MoneyFormatter;
use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductCatalogManager;
use App\Modules\Products\Services\ProductsAccess;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table->modifyQueryUsing(function (Builder $query): Builder {
            $query->with(['images' => fn ($images) => $images->whereNull('product_variant_id')->orderByDesc('is_primary')->orderBy('sort_order')])->withMin('variants', 'sale_price')->withMax('variants', 'sale_price');
            if (app(ProductsAccess::class)->referenceCost(auth()->user())) {
                $query->withMin('variants', 'cost_price');
            }

            return $query;
        })->columns([
            ImageColumn::make('thumbnail')->label(__('Image'))->getStateUsing(fn (Product $record): ?string => $record->images->firstWhere('disk', 'public')?->path)->disk('public')->defaultImageUrl(asset('images/product-placeholder.svg'))->imageSize(48),
            TextColumn::make('name')->label(__('Name'))->searchable(query: function (Builder $query, string $search): Builder {
                return $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('default_sku', 'like', '%'.$search.'%')->orWhereHas('variants', fn (Builder $variants): Builder => $variants->where('sku', 'like', '%'.$search.'%')->orWhere('barcode', 'like', '%'.$search.'%'));
                });
            })->sortable(),
            IconColumn::make('is_active')->toggleable(isToggledHiddenByDefault: true)->label(__('Active'))->boolean(),
            TextColumn::make('default_sku')->label(__('Catalog SKU')),
            TextColumn::make('brand.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Brand')),
            TextColumn::make('category.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Category')),
            TextColumn::make('variants_min_sale_price')->label(__('Sale price from'))->state(fn ($record) => $record->has_fixed_selling_price ? $record->sale_price : $record->variants_min_sale_price)->formatStateUsing(fn ($state): string => MoneyFormatter::precise($state))->placeholder(__('No variants')),
            TextColumn::make('variants_max_sale_price')->label(__('Sale price to'))->state(fn ($record) => $record->has_fixed_selling_price ? $record->sale_price : $record->variants_max_sale_price)->formatStateUsing(fn ($state): string => MoneyFormatter::precise($state))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('variants_min_cost_price')->toggleable(isToggledHiddenByDefault: true)->label(__('Reference cost from'))->formatStateUsing(fn ($state): string => MoneyFormatter::precise($state))->visible(fn (): bool => app(ProductsAccess::class)->referenceCost(auth()->user())),
            TextColumn::make('status')->label(__('Status'))->formatStateUsing(fn ($state): string => __(ucfirst($state->value)))->badge()->color(fn ($state): string => match ($state->value) {
                'active' => 'success', 'draft' => 'warning', default => 'gray'
            }),
            TextColumn::make('variants_count')->label(__('Variants'))->counts('variants')])->filters([TernaryFilter::make('is_active')->label(__('Active')),
                SelectFilter::make('status')->label(__('Status'))->options(['draft' => __('Draft'), 'active' => __('Active'), 'archived' => __('Archived')]),
                SelectFilter::make('brand')->label(__('Brand'))->relationship('brand', 'name')->searchable()->preload(),
                SelectFilter::make('category')->label(__('Category'))->relationship('category', 'name')->searchable()->preload(),
                Filter::make('missing_variants')->label(__('Missing variants'))->query(fn (Builder $query): Builder => $query->doesntHave('variants'))])->defaultSort('name')->recordAction(null)->recordActions([
                    ViewAction::make(), EditAction::make(), DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app(ProductCatalogManager::class)->delete(auth()->user(), $record))),
                ]);
    }
}
