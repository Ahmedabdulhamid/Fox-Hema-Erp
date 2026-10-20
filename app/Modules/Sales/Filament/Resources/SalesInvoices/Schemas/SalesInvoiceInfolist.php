<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class SalesInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            Section::make(__('invoices.invoice'))->columns(3)->schema([
                TextEntry::make('invoice_number')->label(__('invoices.invoice_number'))->copyable(),
                TextEntry::make('salesOrder.order_number')->label(__('invoices.order')),
                TextEntry::make('location.name')->label(__('invoices.branch')),
                TextEntry::make('status')->label(__('invoices.status'))->badge()->formatStateUsing(fn (string $state): string => __('invoices.'.$state)),
                TextEntry::make('invoice_date')->label(__('invoices.issue_date'))->date(),
                TextEntry::make('due_date')->label(__('invoices.due_date'))->date(),
                TextEntry::make('customer_name')->label(__('invoices.customer')),
                TextEntry::make('customer_phone')->label(__('invoices.customer_phone')),
                TextEntry::make('currency')->label(__('invoices.currency')),
                TextEntry::make('createdBy.name')->label(__('invoices.created_by')),
                TextEntry::make('posted_at')->label(__('invoices.posted_at'))->dateTime(),
                TextEntry::make('postedBy.name')->label(__('invoices.posted_by')),
                TextEntry::make('cancelled_at')->label(__('invoices.cancelled_at'))->dateTime(),
                TextEntry::make('cancelledBy.name')->label(__('invoices.cancelled_by')),
            ]),
            Section::make(__('invoices.items'))->schema([
                RepeatableEntry::make('items')->label(__('invoices.items'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence'])
                    ->table(array_map(fn ($label) => TableColumn::make(__('invoices.'.$label))->wrapHeader(),
                        ['product', 'sku', 'size', 'color', 'delivery_line', 'delivered', 'invoiced', 'unit_price', 'subtotal', 'discount', 'tax', 'total']))->schema([
                            TextEntry::make('commercial_snapshot.product_name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state]))->label(__('invoices.product')),
                            TextEntry::make('commercial_snapshot.sku')->label(__('invoices.sku')),
                            TextEntry::make('commercial_snapshot.size')->label(__('invoices.size')),
                            TextEntry::make('commercial_snapshot.color')->label(__('invoices.color')),
                            TextEntry::make('commercial_snapshot.delivery_number')->label(__('invoices.delivery_line')),
                            TextEntry::make('commercial_snapshot.delivered_quantity')->label(__('invoices.delivered')),
                            TextEntry::make('quantity')->label(__('invoices.invoiced')),
                            TextEntry::make('unit_price')->label(__('invoices.unit_price')),
                            TextEntry::make('subtotal')->label(__('invoices.subtotal')),
                            TextEntry::make('discount_amount')->label(__('invoices.discount')),
                            TextEntry::make('tax_amount')->label(__('invoices.tax')),
                            TextEntry::make('line_total')->label(__('invoices.total')),
                        ]),
            ]),
            Section::make(__('invoices.grand_total'))->columns(4)->schema([
                TextEntry::make('subtotal')->label(__('invoices.subtotal')),
                TextEntry::make('discount_amount')->label(__('invoices.discount')),
                TextEntry::make('tax_amount')->label(__('invoices.tax')),
                TextEntry::make('total_amount')->label(__('invoices.grand_total')),
            ]),
            TextEntry::make('notes')->label(__('invoices.notes')),
        ])->columns(1);
    }
}
