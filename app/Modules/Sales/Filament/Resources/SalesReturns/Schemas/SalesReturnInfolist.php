<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns\Schemas;

use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Services\SalesReturnQuantityService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class SalesReturnInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $header = [];
        foreach (['return_number', 'salesOrder.order_number' => 'order', 'location.name' => 'branch', 'return_date',
            'createdBy.name' => 'created_by', 'approvedBy.name' => 'approved_by', 'approved_at',
            'postedBy.name' => 'posted_by', 'posted_at',
            'cancelledBy.name' => 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'reason', 'notes'] as $key => $label) {
            $header[] = TextEntry::make(is_int($key) ? $label : $key)->label(__('returns.'.$label));
        }
        $header[] = TextEntry::make('status')->label(__('returns.status'))->badge()->formatStateUsing(fn ($state) => __('returns.'.$state));
        $header[] = TextEntry::make('approval_notice')->label(__('returns.approval_notice'))
            ->state(__('returns.self_approval_notice'))
            ->visible(fn (SalesReturn $record): bool => $record->status === 'draft' && auth()->id() !== null
                && (int) auth()->id() === (int) $record->created_by)
            ->columnSpanFull();
        $lines = [];
        foreach (['salesDeliveryItem.salesDelivery.delivery_number' => 'delivery_line', 'variant.product.name' => 'product',
            'variant.sku' => 'sku', 'variant.size.name' => 'size', 'variant.color.name' => 'color',
            'quantity' => 'quantity', 'unbilled_quantity' => 'unbilled_quantity'] as $path => $label) {
            $lines[] = TextEntry::make($path)->label(__('returns.'.$label));
        }
        $lines[] = TextEntry::make('disposition')->label(__('returns.disposition'))->formatStateUsing(fn ($state) => __('returns.'.$state));
        foreach (['delivered', 'posted', 'claimed', 'remaining'] as $key) {
            $lines[] = TextEntry::make('summary_'.$key)->label(__('returns.'.($key === 'posted' ? 'previously_returned' : $key)))
                ->state(fn (SalesReturnItem $record): string => app(SalesReturnQuantityService::class)->quantities([$record->sales_delivery_item_id], lock: false)[$record->sales_delivery_item_id][$key]);
        }
        $lines[] = RepeatableEntry::make('invoiceAllocations')->label(__('returns.invoice_allocations'))->columnSpanFull()->schema([
            TextEntry::make('salesInvoice.invoice_number')->label(__('returns.invoice')),
            TextEntry::make('quantity')->label(__('returns.quantity')),
        ])->columns(2);
        $lines[] = RepeatableEntry::make('postedAllocations')->label(__('returns.posting_allocations'))->columnSpanFull()->schema([
            TextEntry::make('salesInvoice.invoice_number')->label(__('returns.invoice'))->placeholder(__('returns.unbilled_quantity')),
            TextEntry::make('quantity')->label(__('returns.quantity')),
        ])->columns(2);
        $costAccess = fn (): bool => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::COST);
        $lines[] = RepeatableEntry::make('costAllocations')->label(__('returns.physical_evidence'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence'])->schema([
            TextEntry::make('quantity')->label(__('returns.quantity')),
            TextEntry::make('disposition')->label(__('returns.disposition'))->formatStateUsing(fn ($s) => __('returns.'.$s)),
            TextEntry::make('stockMovement.display_reference')->label(__('returns.movement_reference')),
            TextEntry::make('quarantineReceipt.display_reference')->label(__('returns.custody_reference')),
            TextEntry::make('scrapReceipt.disposal_status')->label(__('returns.inspection_status'))->formatStateUsing(fn ($s) => $s ? __('returns.'.$s) : null),
            TextEntry::make('original_cost')->label(__('returns.original_cost'))->visible($costAccess),
            TextEntry::make('carrying_value')->label(__('returns.carrying_value'))->visible($costAccess),
            TextEntry::make('return_variance')->label(__('returns.return_variance'))->visible($costAccess),
            TextEntry::make('loss_value')->label(__('returns.loss_value'))->visible($costAccess),
        ])->columns(3);

        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            Section::make(__('returns.return'))->schema($header)->columns(3),
            Section::make(__('returns.items'))->schema([
                RepeatableEntry::make('items')->key('return-line-table')->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence'])
                    ->table(array_map(fn ($label) => TableColumn::make(__('returns.'.$label))->wrapHeader(),
                        ['product', 'sku', 'size', 'color', 'quantity', 'disposition', 'delivery_line']))->schema([
                            TextEntry::make('variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state])), TextEntry::make('variant.sku'), TextEntry::make('variant.size.name'),
                            TextEntry::make('variant.color.name'), TextEntry::make('quantity'),
                            TextEntry::make('disposition')->formatStateUsing(fn ($state) => __('returns.'.$state)),
                            TextEntry::make('salesDeliveryItem.salesDelivery.display_reference'),
                        ]),
            ]),
            Section::make(__('documents.evidence'))->collapsible()->collapsed()->schema([
                RepeatableEntry::make('items')->key('return-line-evidence')->label(__('returns.items'))->columnSpanFull()
                    ->schema($lines)->columns(['default' => 1, 'md' => 2, 'xl' => 4]),
            ]),
        ])->columns(1);
    }
}
