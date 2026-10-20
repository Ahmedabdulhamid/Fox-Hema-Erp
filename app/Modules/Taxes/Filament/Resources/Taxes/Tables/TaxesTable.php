<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes\Tables;

use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Taxes\Services\TaxManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TaxesTable
{
    protected static string $kind = 'taxes';

    public static function configure(Table $table): Table
    {
        $tax = static::$kind === 'taxes';
        $manager = $tax ? TaxManager::class : DiscountManager::class;
        $start = $tax ? 'effective_from' : 'starts_at';
        $end = $tax ? 'effective_to' : 'ends_at';
        $columns = [
            TextColumn::make('usage')->label(__('Usage'))->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
            TextColumn::make('code')->label(__('Code'))->searchable()->toggleable(),
            TextColumn::make('type')->label(__('Type'))->formatStateUsing(fn (string $state): string => __($state === 'percentage' ? 'Percentage' : 'Fixed'))->sortable(),
            TextColumn::make($tax ? 'rate' : 'value')->label(__($tax ? 'Rate' : 'Value'))->sortable(),
            IconColumn::make('is_active')->label(__('Active'))->boolean(),
            TextColumn::make('products_count')->label(__('Products'))->counts('products'),
        ];
        if ($tax) {
            $columns = [...$columns,
                TextColumn::make('price_inclusion')->label(__('Price inclusion'))->formatStateUsing(fn (string $state): string => __($state === 'included' ? 'Included' : 'Excluded'))->toggleable(),
            ];
        } else {
            $columns = [...$columns,
                TextColumn::make('scope')->label(__('Scope'))->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                IconColumn::make('is_stackable')->label(__('Stackable'))->boolean()->toggleable(),
            ];
        }
        $columns[] = TextColumn::make($start)->label(__($tax ? 'Effective from' : 'Starts at'))->dateTime()->toggleable(isToggledHiddenByDefault: true);
        $columns[] = TextColumn::make($end)->label(__($tax ? 'Effective to' : 'Ends at'))->dateTime()->toggleable(isToggledHiddenByDefault: true);

        return $table->columns($columns)->filters([
            TernaryFilter::make('is_active')->label(__('Active')),
            SelectFilter::make('type')->label(__('Type'))->options(['percentage' => __('Percentage'), 'fixed' => __('Fixed')]),
            SelectFilter::make('usage')->label(__('Usage'))->options(['sales' => __('Sales'), 'purchases' => __('Purchases'), 'both' => __('Both')]),
            SelectFilter::make($tax ? 'price_inclusion' : 'scope')->label(__($tax ? 'Price inclusion' : 'Scope'))->options($tax ? ['excluded' => __('Excluded'), 'included' => __('Included')] : ['product' => __('Product'), 'order' => __('Order')]),
            Filter::make('currently_effective')->label(__('Currently effective'))->query(function (Builder $query) use ($start, $end, $tax): Builder {
                $now = $tax ? now()->toDateString() : now()->toDateTimeString();

                return $query->where('is_active', true)
                    ->where(fn (Builder $query) => $query->whereNull($start)->when($tax, fn (Builder $query) => $query->orWhereDate($start, '<=', $now), fn (Builder $query) => $query->orWhere($start, '<=', $now)))
                    ->where(fn (Builder $query) => $query->whereNull($end)->when($tax, fn (Builder $query) => $query->orWhereDate($end, '>=', $now), fn (Builder $query) => $query->orWhere($end, '>=', $now)));
            }),
        ])->defaultSort('name')->recordActions([
            ViewAction::make(), EditAction::make(),
            DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app($manager)->delete(auth()->user(), $record))),
        ]);
    }
}
