<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages;

use App\Models\QuantityFormatter;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchasePriceHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = 'purchasing::price-history';

    public function mount(): void
    {
        abort_unless(PurchaseOrderResource::canViewAny(), 403);
    }

    public function getTitle(): string
    {
        return __('Supplier purchase-price history');
    }

    public function table(Table $table): Table
    {
        return $table->query(fn (): Builder => app(PurchaseOrderService::class)->history(auth()->user()))->columns([
            TextColumn::make('variant.product.name')->label(__('Product'))->searchable()->formatStateUsing(fn ($record): string => $record->variant->displayName()),
            TextColumn::make('variant.sku')->label(__('Variant SKU'))->searchable(),
            TextColumn::make('purchaseOrder.supplier.name')->label(__('Supplier'))->searchable(),
            TextColumn::make('purchaseOrder.location.name')->label(__('Location')),
            TextColumn::make('purchaseOrder.order_number')->label(__('PO number'))->searchable(),
            TextColumn::make('purchaseOrder.order_date')->label(__('Order date'))->date(),
            TextColumn::make('quantity')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Ordered quantity'))->sortable(),
            TextColumn::make('unit_price')->label(__('Unit purchase price (EGP)'))->sortable(),
            TextColumn::make('discount_amount')->label(__('Discount amount'))->sortable(),
            TextColumn::make('tax_amount')->label(__('Tax amount'))->sortable(),
            TextColumn::make('line_total')->label(__('Line total (EGP)'))->sortable(),
            TextColumn::make('purchaseOrder.status')->label(__('Document status'))->badge()->formatStateUsing(fn (string $state): string => PurchaseOrdersTable::statuses()[$state] ?? $state),
        ])->filters([
            SelectFilter::make('variant_id')->label(__('Product variant'))->searchable()
                ->relationship('variant', 'sku', fn (Builder $query): Builder => $query->whereIn('id', app(PurchaseOrderService::class)->variants(auth()->user(), false)->select('id'))),
            SelectFilter::make('supplier')->label(__('Supplier'))->searchable()->options(fn (): array => app(PurchaseOrderService::class)->suppliers(auth()->user(), false)->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $supplier): Builder => $query->whereHas('purchaseOrder', fn (Builder $orders): Builder => $orders->where('supplier_id', $supplier)))),
            PurchaseOrdersTable::dates()->query(fn (Builder $query, array $data): Builder => $query->whereHas('purchaseOrder', fn (Builder $orders): Builder => $orders
                ->when($data['from'] ?? null, fn (Builder $orders, $date): Builder => $orders->whereDate('order_date', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $orders, $date): Builder => $orders->whereDate('order_date', '<=', $date)))),
        ])->recordActions([])->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('orders')->label(__('Purchase Orders'))->url(PurchaseOrderResource::getUrl())];
    }
}
