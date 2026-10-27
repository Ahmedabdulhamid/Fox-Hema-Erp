<?php

namespace App\Modules\Inventory\Filament;

use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryWorkspace;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\FiltersResetActionPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class InventoryFilters
{
    public static function configure(Table $table): Table
    {
        return $table->filtersLayout(FiltersLayout::Hidden)
            ->header(fn (HasTable $livewire): View => view('inventory.table-filter-header', ['inventoryLivewire' => $livewire]))
            ->filtersResetActionPosition(FiltersResetActionPosition::Footer)
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->filtersFormWidth('4xl')->filtersFormMaxHeight('min(70dvh, calc(100dvh - 8rem))')
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->icon('heroicon-o-funnel')->label(__('Filters')))
            ->filtersApplyAction(fn (Action $action): Action => $action->icon('heroicon-o-funnel')->label(__('Apply Filters')))
            ->filtersResetAction(fn (Action $action): Action => $action->button()->icon('heroicon-o-arrow-path')->label(__('Reset Filters'))->color('gray'))
            ->deferFilters()->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-archive-box')->emptyStateHeading(__('No matching inventory records'))
            ->emptyStateDescription(__('Try another location or adjust your filters. No stock quantities have been changed.'));
    }

    /** @return array<int, SelectFilter> */
    public static function catalog(string $permission, string $kind, string $productKey = 'product', bool $color = false): array
    {
        $fields = ['location_id' => __('Branch / warehouse'), 'brand' => __('Brand'), $productKey => __('Product')];
        $fields[$color ? 'color' : 'variant'] = $color ? __('Color') : __('Variant');
        $filters = [];
        $keys = array_keys($fields);
        foreach ($fields as $key => $label) {
            $children = array_slice($keys, array_search($key, $keys, true) + 1);
            $filter = SelectFilter::make($key)->label($label)->searchable()->placeholder(__('All'))
                ->modifyFormFieldUsing(fn (Select $field): Select => $field->live()->afterStateUpdated(function (Set $set) use ($children): void {
                    foreach ($children as $child) {
                        $set('../'.$child.'.value', null);
                    }
                }));
            if ($key === 'location_id') {
                $filter->options(fn (): array => app(InventoryAccess::class)->locations(auth()->user(), $permission)->orderBy('name')->pluck('name', 'id')->all());
            } else {
                $options = fn (InventoryListRecords $livewire, string $search = '', mixed $selected = null): array => app(InventoryWorkspace::class)->options(auth()->user(), $permission, $kind, $key, $livewire->tableDeferredFilters ?? $livewire->tableFilters ?? [], $search, $selected);
                $filter->options(fn (InventoryListRecords $livewire): array => $options($livewire))
                    ->getSearchResultsUsing(fn (string $search, InventoryListRecords $livewire): array => $options($livewire, $search))
                    ->getOptionLabelUsing(fn (mixed $value, InventoryListRecords $livewire): ?string => $options($livewire, selected: $value)[$value] ?? null);
            }
            $filter->query(fn (Builder $query, array $data): Builder => app(InventoryWorkspace::class)->catalog($query, [$key => $data], $kind === 'allocations'));
            $filters[] = $filter;
        }

        return $filters;
    }

    public static function dateRange(string $name, string $column): Filter
    {
        return Filter::make($name)->label(__('Date range'))->schema([
            DatePicker::make('from')->label(__('From')),
            DatePicker::make('until')->label(__('Until'))->afterOrEqual('from'),
        ])->columns(['default' => 1, 'sm' => 2])->columnSpan(['default' => 'full', 'xl' => 'full'])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where($column, '>=', Carbon::parse($date)->startOfDay()))
                ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where($column, '<', Carbon::parse($date)->addDay()->startOfDay())));
    }
}
