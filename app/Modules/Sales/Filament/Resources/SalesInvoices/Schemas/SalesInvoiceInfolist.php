<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Schemas;

use App\Models\MoneyFormatter;
use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Products\Filament\MasterDetail;
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
                RepeatableEntry::make('items')->label(__('invoices.items'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence fox-master-detail-table'])
                    ->table(array_map(fn (string $label): TableColumn => TableColumn::make(__($label))->wrapHeader(),
                        ['Product', 'Variant', 'Quantity', 'Unit price', 'Line total', 'View Details']))->schema([
                            TextEntry::make('commercial_snapshot.product_name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state]))->label(__('invoices.product')),
                            View::make('components.variant-summary')->viewData(fn ($record): array => ['variant' => $record->variant]),
                            TextEntry::make('quantity')->label(__('invoices.invoiced')),
                            TextEntry::make('unit_price')->label(__('invoices.unit_price')),
                            TextEntry::make('line_total')->label(__('invoices.total')),
                            MasterDetail::entry(fn (): array => self::lineDetails()),
                        ]),
            ]),
            Section::make(__('invoices.grand_total'))->columns(4)->schema([
                TextEntry::make('subtotal')->label(__('invoices.subtotal')),
                TextEntry::make('discount_amount')->label(__('invoices.discount')),
                TextEntry::make('tax_amount')->label(__('invoices.tax')),
                TextEntry::make('total_amount')->label(__('invoices.grand_total')),
            ]),
            TextEntry::make('customer_settlement')->label(__('payments.invoice_payments'))
                ->visible(fn ($record) => CustomerSettlementActions::can(PaymentsPermissions::VIEW, (int) $record->location_id))
                ->state(function ($record): string {
                    $link = CustomerInvoiceAccount::where('sales_invoice_id', $record->id)->first();
                    $summary = app(CustomerFinancials::class)->invoice(auth()->user(), $record);

                    return ($link === null ? __('payments.walk_in') : $link->customer->code).' | '.__('Paid').': '.MoneyFormatter::format($summary['paid']).' | '.__('Outstanding').': '.MoneyFormatter::format($summary['outstanding']).' | '.__('payments.status.'.$summary['payment_status']);
                }),
            RepeatableEntry::make('invoice_payments')->label(__('payments.invoice_payments'))
                ->visible(fn ($record) => CustomerSettlementActions::can(PaymentsPermissions::VIEW, (int) $record->location_id))
                ->state(fn ($record) => Payment::whereHas('allocations', fn ($query) => $query->where('sales_invoice_id', $record->id))->where('status', 'posted')->with('paymentMethod')->withSum(['refunds as refunded_amount' => fn ($query) => $query->where('status', 'posted')->whereNull('recovered_at')], 'amount')->get())
                ->schema([
                    TextEntry::make('payment_number')->label(__('payments.fields.payment_number')),
                    TextEntry::make('paymentMethod.name')->label(__('payments.fields.payment_method_id')),
                    TextEntry::make('amount')->label(__('payments.fields.amount')),
                    TextEntry::make('payment_date')->label(__('payments.fields.payment_date'))->date(),
                    TextEntry::make('refunded_amount')->label(__('payments.refunded_amount'))->default('0.0000'),
                    TextEntry::make('external_reference')->label(__('payments.fields.external_reference')),
                ])->columnSpanFull(),
            TextEntry::make('notes')->label(__('invoices.notes')),
        ])->columns(1);
    }

    private static function lineDetails(): array
    {
        return [
            Section::make(__('Product'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('commercial_snapshot.product_name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state]))->label(__('invoices.product')),
                TextEntry::make('commercial_snapshot.sku')->label(__('invoices.sku')),
                TextEntry::make('commercial_snapshot.size')->label(__('invoices.size')),
                TextEntry::make('commercial_snapshot.color')->label(__('invoices.color')),
            ]),
            Section::make(__('Delivery details'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('commercial_snapshot.delivery_number')->label(__('invoices.delivery_line')),
                TextEntry::make('commercial_snapshot.delivered_quantity')->label(__('invoices.delivered')),
                TextEntry::make('quantity')->label(__('invoices.invoiced')),
            ]),
            Section::make(__('Financial totals'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('unit_price')->label(__('invoices.unit_price')),
                TextEntry::make('subtotal')->label(__('invoices.subtotal')),
                TextEntry::make('discount_amount')->label(__('invoices.discount')),
                TextEntry::make('tax_amount')->label(__('invoices.tax')),
                TextEntry::make('line_total')->label(__('invoices.total')),
            ]),
        ];
    }
}
