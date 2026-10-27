<?php

namespace App\Modules\Inventory\Livewire;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Filament\InventoryFilters;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Services\AllocationStockStatus;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryProductPresentation;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class AllocationStockTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
        app(InventoryAccess::class)->authorize(auth()->user(), InventoryPermissions::ALLOCATIONS_VIEW);
        app(InventoryAccess::class)->authorize(auth()->user(), InventoryPermissions::BALANCES);
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return ['needs' => __('Needs Allocation'), 'all' => __('All'), 'unallocated' => __('Unallocated'), 'partial' => __('Partially Allocated'), 'full' => __('Fully Allocated'), 'review' => __('Needs review')];
    }

    #[Computed]
    public function canManage(): bool
    {
        return app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::ALLOCATIONS_MANAGE);
    }

    #[Computed]
    public function canReadReceipts(): bool
    {
        return app(PurchasingAccess::class)->allows(auth()->user(), PurchasingPermissions::RECEIPTS_VIEW);
    }

    public function table(Table $table): Table
    {
        $service = app(AllocationStockStatus::class);

        return InventoryFilters::configure($table)->query(fn (): Builder => $service->filter($service->query(auth()->user()), auth()->user(), $this->tableFilters ?? []))
            ->columns([
                TextColumn::make('product.name')->label(__('Product'))->searchable()->sortable()
                    ->action(fn (ProductVariant $record) => $this->mountTableAction('poolDetails', $this->getTableRecordKey($record)))
                    ->formatStateUsing(fn ($state, ProductVariant $record) => view('components.inventory-product', ['variant' => $record, 'title' => $state])),
                TextColumn::make('product.brand.name')->label(__('Brand'))->placeholder(__('No brand'))->toggleable(),
                TextColumn::make('sku')->label(__('SKU'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('barcode')->label(__('Barcode'))->searchable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('location_name')->label(__('Location'))->sortable(),
                TextColumn::make('receipt_count')->label(__('Purchase Receipt'))->visible(fn (): bool => $this->canReadReceipts)->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state): string => __('Shared pool: :count receipts', ['count' => $state])),
                TextColumn::make('received_quantity')->label(__('Received quantity'))->sortable()->formatStateUsing(fn ($state): string => QuantityFormatter::format((string) $state)),
                TextColumn::make('allocated_quantity')->label(__('Allocated quantity'))->sortable()->formatStateUsing(fn ($state): string => QuantityFormatter::format((string) $state)),
                TextColumn::make('remaining_quantity')->label(__('Remaining quantity'))->sortable()->badge()->color('warning')->placeholder(__('Missing balance'))
                    ->formatStateUsing(fn ($state): string => QuantityFormatter::format((string) $state)),
                TextColumn::make('quantity_available')->label(__('Available to allocate'))->sortable()->toggleable(isToggledHiddenByDefault: true)->formatStateUsing(fn ($state): string => QuantityFormatter::format((string) $state))->placeholder(__('Missing balance')),
                TextColumn::make('progress')->label(__('Allocation progress'))->state(fn (ProductVariant $record) => view('inventory.allocation-progress', ['pool' => $record]))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('allocation_state')->label(__('Allocation status'))->badge()
                    ->formatStateUsing(fn (string $state): string => self::statuses()[$state])
                    ->color(fn (string $state): string => match ($state) {
                        'unallocated' => 'danger', 'partial' => 'warning', 'full' => 'success', default => 'gray'
                    }),
            ])->filters([
                SelectFilter::make('allocation_state')->label(__('Allocation status'))->options(self::statuses())->default('needs')
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->hidden())
                    ->query(fn (Builder $query, array $data): Builder => $service->status($query, $data['value'] ?? 'needs')),
                SelectFilter::make('location_id')->label(__('Branch / warehouse'))->searchable()
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->live()->afterStateUpdated(function (Set $set): void {
                        foreach (['brand', 'product_id', 'supplier', 'receipt'] as $child) {
                            $set('../'.$child.'.value', null);
                        }
                    }))
                    ->options(fn (): array => app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::ALLOCATIONS_VIEW)->orderBy('name')->pluck('name', 'id')->all())->query(fn (Builder $query): Builder => $query),
                $this->catalogFilter('brand', __('Brand')),
                $this->catalogFilter('product_id', __('Product')),
                $this->receiptFilter('supplier', __('Supplier')),
                $this->receiptFilter('receipt', __('Purchase Receipt')),
            ])
            ->persistFiltersInSession()->persistSearchInSession()
            ->recordActions([
                Action::make('allocateSizes')->label(__('Allocate'))->icon('heroicon-o-squares-plus')
                    ->visible(fn (ProductVariant $record): bool => $this->canAllocate($record))
                    ->url(fn (ProductVariant $record): string => SizeAllocationResource::getUrl('create', ['product' => $record->product_id, 'location' => $record->allocation_location_id])),
                Action::make('poolDetails')->label(fn (ProductVariant $record): string => $record->allocation_state === 'review' ? __('Review') : __('View Details'))
                    ->alpineClickHandler(fn (ProductVariant $record): string => "\$wire.mountTableAction('poolDetails', '".$this->getTableRecordKey($record)."')")
                    ->modal()->modalHeading(__('Allocation details'))->modalWidth('7xl')->modalSubmitAction(false)
                    ->modalContent(function (ProductVariant $record): View {
                        $documents = SizeAllocationResource::getEloquentQuery()->where('source_variant_id', $record->id)->where('location_id', $record->allocation_location_id)->latest('id')->limit(20)->get();
                        $receipts = $this->canReadReceipts
                            ? app(AllocationStockStatus::class)->receipts(auth()->user())->where('m.variant_id', $record->id)->where('r.location_id', $record->allocation_location_id)
                                ->join('suppliers as supplier', 'supplier.id', '=', 'po.supplier_id')
                                ->select('r.id', 'r.uuid', 'r.receipt_number', 'supplier.name as supplier_name')->distinct()->orderByDesc('r.id')->limit(20)->get() : collect();
                        $variants = ProductVariant::query()->where('product_id', $record->product_id)->whereNull('unallocated_product_id')
                            ->with(['color', 'size'])->get();
                        $allocationLines = DB::table('inventory_size_allocation_items as item')
                            ->join('inventory_size_allocations as allocation', 'allocation.id', '=', 'item.allocation_id')
                            ->join('product_variants as variant', 'variant.id', '=', 'item.variant_id')
                            ->where('allocation.source_variant_id', $record->id)
                            ->where('allocation.location_id', $record->allocation_location_id)
                            ->where('allocation.status', 'posted')->whereNotNull('allocation.posted_at')->whereNotNull('allocation.posted_by')
                            ->where('variant.product_id', $record->product_id)->whereNull('variant.unallocated_product_id')
                            ->select('variant.color_id', 'variant.size_id')->selectRaw('SUM(item.quantity) AS quantity')
                            ->groupBy('variant.color_id', 'variant.size_id')->get();
                        $colors = $variants->pluck('color')->filter()->unique('id')->sortBy('sort_order')->values();
                        if ($variants->contains(fn (ProductVariant $variant): bool => $variant->color_id === null)) {
                            $colors->prepend((object) ['id' => 0, 'name' => __('No color'), 'sort_order' => -1]);
                        }
                        $sizes = $variants->pluck('size')->filter()->unique('id')->sortBy('sort_order')->values();
                        $quantities = $allocationLines->mapWithKeys(fn ($line): array => [($line->color_id ?? 0).'-'.$line->size_id => (string) $line->quantity]);
                        $matrix = $colors->map(function ($color) use ($sizes, $quantities): array {
                            $cells = $sizes->mapWithKeys(fn ($size): array => [
                                $size->id => $quantities->get($color->id.'-'.$size->id, '0'),
                            ])->all();
                            $total = array_reduce($cells, fn (BigDecimal $sum, string $quantity): BigDecimal => $sum->plus($quantity), BigDecimal::zero());

                            return ['label' => $color->name, 'hex' => $color->hex_code ?? null, 'cells' => $cells, 'total' => (string) $total];
                        });
                        $columnTotals = $sizes->mapWithKeys(function ($size) use ($allocationLines): array {
                            $total = $allocationLines->where('size_id', $size->id)
                                ->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus((string) $line->quantity), BigDecimal::zero());

                            return [$size->id => (string) $total];
                        })->all();
                        $allocatedTotal = $allocationLines->reduce(
                            fn (BigDecimal $sum, $line): BigDecimal => $sum->plus((string) $line->quantity),
                            BigDecimal::zero(),
                        );

                        return view('inventory.allocation-pool-details', [
                            'pool' => $record,
                            'documents' => $documents,
                            'receipts' => $receipts,
                            'colors' => $colors,
                            'variants' => $variants,
                            'sizes' => $sizes,
                            'matrix' => $matrix,
                            'columnTotals' => $columnTotals,
                            'allocatedTotal' => (string) $allocatedTotal,
                            'canAllocate' => $this->canAllocate($record),
                        ]);
                    }),
            ])->recordUrl(null)->defaultSort(fn (Builder $query): Builder => $query->orderByRaw("CASE WHEN allocation_state = 'review' THEN 0 WHEN allocation_state = 'unallocated' THEN 1 WHEN allocation_state = 'partial' THEN 2 ELSE 3 END")->orderByDesc('remaining_quantity')->orderBy('id')->orderBy('allocation_location_id'))
            ->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateIcon('heroicon-o-check-badge')->emptyStateHeading(__('No allocation pools match this view'))
            ->emptyStateDescription(__('No pending pools may mean everything is allocated. Switch to All or Fully Allocated, or check your filters.'));
    }

    public function canAllocate(ProductVariant $record): bool
    {
        return $this->canManage && app(InventoryProductPresentation::class)->canAllocate(auth()->user(), $record);
    }

    public function detailsPage(int $page): void
    {
        abort_unless($page > 0 && $page <= 100000, 422);
        $this->detailsColorPage = $page;
    }

    private function catalogFilter(string $name, string $label): SelectFilter
    {
        $options = function (string $search = '', mixed $selected = null) use ($name): array {
            $pools = app(AllocationStockStatus::class)->query(auth()->user());
            $filters = $this->tableDeferredFilters ?? $this->tableFilters ?? [];
            $pools->when(filled($filters['location_id']['value'] ?? null), fn (Builder $query) => $query->where('allocation_location_id', $filters['location_id']['value']));
            if ($name === 'product_id' && filled($filters['brand']['value'] ?? null)) {
                $brand = $filters['brand']['value'];
                $pools->whereHas('product', fn (Builder $query) => $query->where('brand_id', $brand === 'none' ? null : $brand));
            }
            $products = Product::whereIn('id', $pools->select('product_id'));
            $hasNoBrand = $name === 'brand' && (clone $products)->whereNull('brand_id')->exists();
            if ($selected === 'none') {
                return $hasNoBrand ? ['none' => __('No brand')] : [];
            }
            $query = $name === 'brand' ? Brand::whereIn('id', (clone $products)->select('brand_id')) : $products;

            $options = $query->when($selected !== null, fn (Builder $q) => $q->whereKey($selected))
                ->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(50)->pluck('name', 'id')->all();
            if ($hasNoBrand && $selected === null && ($search === '' || mb_stripos(__('No brand'), $search) !== false)) {
                $options = ['none' => __('No brand')] + $options;
            }

            return $options;
        };

        $field = SelectFilter::make($name)->label($label)->searchable()->options(fn (): array => $options())
            ->getSearchResultsUsing(fn (string $search): array => $options($search))
            ->getOptionLabelUsing(fn ($value): ?string => $options(selected: $value)[$value] ?? null)
            ->query(fn (Builder $query): Builder => $query);

        if ($name === 'brand') {
            $field->modifyFormFieldUsing(fn (Select $select): Select => $select->live()->afterStateUpdated(fn (Set $set) => $set('../product_id.value', null)));
        }

        return $field;
    }

    private function receiptFilter(string $name, string $label): SelectFilter
    {
        $options = function (string $search = '', mixed $selected = null) use ($name): array {
            if (! $this->canReadReceipts) {
                return [];
            }
            $query = app(AllocationStockStatus::class)->receipts(auth()->user());
            $filters = $this->tableDeferredFilters ?? $this->tableFilters ?? [];
            $query->when(filled($filters['location_id']['value'] ?? null), fn (QueryBuilder $builder) => $builder->where('r.location_id', $filters['location_id']['value']));
            if ($name === 'receipt' && filled($filters['supplier']['value'] ?? null)) {
                $query->where('po.supplier_id', $filters['supplier']['value']);
            }
            if ($name === 'supplier') {
                $query->join('suppliers as supplier', 'supplier.id', '=', 'po.supplier_id');
            }
            $id = $name === 'supplier' ? 'supplier.id' : 'r.id';
            $text = $name === 'supplier' ? 'supplier.name' : 'r.receipt_number';

            return $query->when($selected !== null, fn (QueryBuilder $q) => $q->where($id, $selected))
                ->where($text, 'like', '%'.$search.'%')->select($id, $text)->distinct()->orderBy($text)->limit(50)->pluck($text, $id)->all();
        };

        $field = SelectFilter::make($name)->label($label)->visible(fn (): bool => $this->canReadReceipts)->searchable()
            ->options(fn (): array => $options())->getSearchResultsUsing(fn (string $search): array => $options($search))
            ->getOptionLabelUsing(fn ($value): ?string => $options(selected: $value)[$value] ?? null)
            ->query(fn (Builder $query): Builder => $query);

        if ($name === 'supplier') {
            $field->modifyFormFieldUsing(fn (Select $select): Select => $select->live()->afterStateUpdated(fn (Set $set) => $set('../receipt.value', null)));
        }

        return $field;
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return $record->id.'-'.$record->allocation_location_id;
    }

    public function getTableRecord(?string $key): Model|array|null
    {
        if ($key === null || ! preg_match('/^([1-9][0-9]*)-([1-9][0-9]*)$/D', $key, $parts)) {
            return null;
        }

        return $this->applyFiltersToTableQuery($this->getTable()->getQuery(isResolvingRecord: true), isResolvingRecord: true)
            ->where('product_variants.id', $parts[1])->where('allocation_location_id', $parts[2])->first();
    }

    public function chooseStatus(string $status): void
    {
        abort_unless(array_key_exists($status, self::statuses()), 422);
        $this->tableFilters ??= [];
        $this->tableDeferredFilters ??= $this->tableFilters;
        $this->tableDeferredFilters['allocation_state']['value'] = $status;
    }

    public function hasPendingFilters(): bool
    {
        return ($this->tableDeferredFilters ?? []) !== ($this->tableFilters ?? []);
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        $filters = $this->tableFilters ?? [];
        unset($filters['allocation_state']);
        $service = app(AllocationStockStatus::class);
        $query = $service->filter($service->query(auth()->user()), auth()->user(), $filters);

        return $service->counts($this->applySearchToTableQuery($query));
    }

    public function render(): View
    {
        return view('inventory.allocation-stock-table');
    }
}
