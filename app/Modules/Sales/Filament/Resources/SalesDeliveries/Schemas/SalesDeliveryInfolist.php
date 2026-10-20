<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries\Schemas;

use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Services\SalesFulfillmentService;
use Brick\Math\BigDecimal;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class SalesDeliveryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            Section::make(__('Sales Delivery'))->columns(3)->schema([
                TextEntry::make('delivery_number')->label(__('Delivery number'))->copyable(),
                TextEntry::make('salesOrder.order_number')->label(__('Sales Order')),
                TextEntry::make('location.name')->label(__('Branch')),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                TextEntry::make('delivered_at')->label(__('Delivery date'))->dateTime(),
                TextEntry::make('createdBy.name')->label(__('Created By')),
                TextEntry::make('posted_at')->label(__('Posted at'))->dateTime(),
                TextEntry::make('postedBy.name')->label(__('Posted by')),
                TextEntry::make('cancelled_at')->label(__('Cancelled at'))->dateTime(),
                TextEntry::make('cancelledBy.name')->label(__('Cancelled by')),
            ]),
            Section::make(__('Delivery items'))->schema([
                RepeatableEntry::make('items')->key('delivery-line-table')->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence'])
                    ->table(array_map(fn ($label) => TableColumn::make(__($label))->wrapHeader(),
                        ['Product', 'SKU', 'Size', 'Color', 'Delivery quantity', 'Stock movement']))->schema([
                            TextEntry::make('salesOrderItem.variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->salesOrderItem->variant, 'title' => $state])), TextEntry::make('salesOrderItem.variant.sku'),
                            TextEntry::make('salesOrderItem.variant.size.name'), TextEntry::make('salesOrderItem.variant.color.name'),
                            TextEntry::make('quantity'), TextEntry::make('stockMovement.display_reference'),
                        ]),
            ]),
            Section::make(__('documents.evidence'))->collapsible()->collapsed()->schema([
                RepeatableEntry::make('items')->key('delivery-line-evidence')->label(__('Delivery items'))->columnSpanFull()->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                    TextEntry::make('salesOrderItem.variant.product.name')->label(__('Product')),
                    TextEntry::make('salesOrderItem.variant.sku')->label(__('SKU')),
                    TextEntry::make('salesOrderItem.variant.size.name')->label(__('Size')),
                    TextEntry::make('salesOrderItem.variant.color.name')->label(__('Color')),
                    TextEntry::make('ordered')->label(__('Ordered quantity'))->state(fn (SalesDeliveryItem $record): string => $record->salesOrderItem->quantity),
                    TextEntry::make('delivered')->label(__('Already delivered quantity'))->state(fn (SalesDeliveryItem $record): string => app(SalesFulfillmentService::class)->quantities($record->salesOrder)[$record->sales_order_item_id]['delivered']),
                    TextEntry::make('remaining')->label(__('Remaining quantity'))->state(fn (SalesDeliveryItem $record): string => app(SalesFulfillmentService::class)->quantities($record->salesOrder)[$record->sales_order_item_id]['remaining']),
                    TextEntry::make('quantity')->label(__('Delivery quantity')),
                    TextEntry::make('stockMovement.display_reference')->label(__('Stock movement')),
                    TextEntry::make('reservationConsumption.quantity')->label(__('Consumed reservation quantity')),
                    TextEntry::make('cogs')->label(__('COGS'))->visible(fn (): bool => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::COST))
                        ->state(fn (SalesDeliveryItem $record): ?string => $record->stockMovement?->inventoryCostEntry?->entry_type !== 'issue' ? null
                            : (string) BigDecimal::of($record->stockMovement->inventoryCostEntry->total_cost)->negated()->toScale(4)),
                ]),
            ]),
            TextEntry::make('notes')->label(__('Notes')),
        ])->columns(1);
    }
}
