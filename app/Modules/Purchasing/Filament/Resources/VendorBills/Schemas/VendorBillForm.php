<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Schemas;

use App\Models\MoneyFormatter;
use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\Products\Filament\ProductLinePreview;
use App\Modules\Purchasing\Filament\BillMutation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Purchasing\Services\VendorBillService;
use Brick\Math\BigDecimal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VendorBillForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Supplier invoice'))->columns(2)->schema([
                Select::make('purchase_order_id')->label(__('Purchase Order'))->required()->searchable()->live()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')->dehydrated()
                    ->options(fn (): array => app(DocumentSelectors::class)->orders(auth()->user(), 'purchase'))
                    ->getSearchResultsUsing(fn (string $search): array => app(DocumentSelectors::class)->orders(auth()->user(), 'purchase', $search))
                    ->getOptionLabelUsing(fn ($value): ?string => app(DocumentSelectors::class)->selectedLabel(auth()->user(), 'purchase', $value))
                    ->formatStateUsing(fn ($state): ?string => app(DocumentSelectors::class)->orderValue(auth()->user(), 'purchase', $state))
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $set('items', []);
                        $set('supplier_id', null);
                        if (! $state) {
                            return;
                        }
                        try {
                            $order = self::authorizedOrder($state);
                            if ($order === null) {
                                $set('purchase_order_id', null);

                                return;
                            }
                            $data = BillMutation::run(fn (): array => app(VendorBillService::class)->prefill(auth()->user(), $order));
                            $data['purchase_order_id'] = $order->uuid;
                            foreach ($data as $field => $value) {
                                $set($field, $value);
                            }
                        } catch (ValidationException $exception) {
                            $set('purchase_order_id', null);
                        }
                    }),
                Hidden::make('supplier_id')->required(),
                Text::make(fn (Get $get): string => __('Supplier').': '.(self::authorizedOrder($get('purchase_order_id'))?->supplier?->name ?? '—')),
                TextInput::make('bill_number')->label(__('Supplier invoice number'))->required()->maxLength(255),
                DatePicker::make('bill_date')->label(__('Bill date'))->default(now())->required(),
                DatePicker::make('due_date')->label(__('Due date'))->afterOrEqual('bill_date'),
                Textarea::make('notes')->label(__('Notes'))->maxLength(16000)->columnSpanFull(),
                Text::make(__('Draft bills do not reserve quantities. Posting checks the saved allocations.'))->columnSpanFull(),
            ]),
            Section::make(__('Receipt matching'))->schema([
                Repeater::make('items')->label(__('Bill lines'))->minItems(1)->maxItems(200)->defaultItems(0)
                    ->addable(false)->reorderable(false)->columns(3)->schema([
                        Hidden::make('variant_id')->required(),
                        ProductLinePreview::make(self::class, 'variant_id', function (Get $get, array $ids): Collection {
                            $order = self::authorizedOrder($get('../../purchase_order_id'));

                            return $order === null ? collect() : $order->items()->whereIn('variant_id', $ids)->with(['variant.images', 'variant.product.images'])->get()->keyBy('variant_id');
                        }),
                        TextInput::make('description')->label(__('Product / Variant'))->required()->maxLength(255)->columnSpanFull(),
                        TextInput::make('quantity')->label(__('Quantity to bill'))->required()->inputMode('decimal')->live(onBlur: true)
                            ->formatStateUsing(fn ($state): ?string => $state === null ? null : QuantityFormatter::format($state))
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $allocations = $get('allocations') ?? [];
                                if (count($allocations) === 1) {
                                    $key = array_key_first($allocations);
                                    $set('allocations.'.$key.'.quantity', $state);
                                }
                            }),
                        TextInput::make('unit_price')->label(__('Invoice unit price'))->required()->inputMode('decimal')->live(onBlur: true),
                        TextInput::make('discount_amount')->label(__('Invoice discount'))->default('0')->required()->inputMode('decimal')->live(onBlur: true)
                            ->readOnly(fn (Get $get): bool => self::usesConfiguredPricing($get)),
                        TextInput::make('tax_amount')->label(__('Invoice tax'))->default('0')->required()->inputMode('decimal')->live(onBlur: true)
                            ->readOnly(fn (Get $get): bool => self::usesConfiguredPricing($get))
                            ->helperText(fn (Get $get): string => self::usesConfiguredPricing($get)
                                ? __('Inherited from confirmed purchase pricing. Unsupported tax and supplier discount policies are blocked.')
                                : __('Historical purchase pricing: review existing invoice amounts before posting.')),
                        Text::make(fn (Get $get): string => self::preview($get))->columnSpanFull(),
                        Repeater::make('allocations')->label(__('Receipt allocations'))->minItems(1)->maxItems(200)->addable(false)->reorderable(false)->deletable(false)->columnSpanFull()->schema([
                            Hidden::make('purchase_receipt_item_id')->required(),
                            Text::make(fn (Get $get): string => self::receiptSummary($get))->columnSpanFull(),
                            TextInput::make('quantity')->label(__('Matched quantity'))->required()->inputMode('decimal')->live(onBlur: true)
                                ->formatStateUsing(fn ($state): ?string => $state === null ? null : QuantityFormatter::format($state)),
                        ]),
                    ]),
                Text::make(fn (Get $get): string => self::preview($get, true)),
            ]),
        ]);
    }

    public static function authorizedOrder(mixed $id): ?PurchaseOrder
    {
        return app(DocumentSelectors::class)->selectedOrder(auth()->user(), 'purchase', $id);
    }

    private static function usesConfiguredPricing(Get $get): bool
    {
        $order = self::authorizedOrder($get('../../purchase_order_id'));

        return $order !== null && $order->items()->whereNotNull('commercial_snapshot')->exists();
    }

    private static function receiptSummary(Get $get): string
    {
        $order = self::authorizedOrder($get('../../../../purchase_order_id'));
        if ($order === null) {
            return __('Select a Purchase Order');
        }
        $service = app(VendorBillService::class);
        $line = $service->receiptLines($order)->firstWhere('id', $get('purchase_receipt_item_id'));
        if ($line === null) {
            return __('Invalid receipt allocation.');
        }
        $remaining = $service->remaining($line);
        $price = $get('../../unit_price');
        $variance = '';
        try {
            $price = app(PurchaseOrderCalculator::class)->decimal($price, 'unit_price', 18, 6);
            if (! BigDecimal::of($price)->isEqualTo($line->unit_cost)) {
                $variance = ' | '.__('Price difference requires review').': '.(string) BigDecimal::of($price)->minus($line->unit_cost).' EGP';
            }
        } catch (ValidationException $exception) {
        }

        return $line->purchaseReceipt->display_reference.' | '.__('Received quantity').': '.QuantityFormatter::format($line->quantity)
            .' | '.__('Previously billed').': '.QuantityFormatter::format((string) BigDecimal::of($line->quantity)->minus($remaining))
            .' | '.__('Remaining billable').': '.QuantityFormatter::format($remaining)
            .' | '.__('PO unit price').': '.MoneyFormatter::format($line->purchaseOrderItem->unit_price)
            .' | '.__('Receipt purchase cost').': '.MoneyFormatter::format($line->unit_cost).$variance;
    }

    private static function preview(Get $get, bool $total = false): string
    {
        try {
            $calculator = app(PurchaseOrderCalculator::class);
            if ($total) {
                $lines = array_map(fn (array $line): array => $calculator->line($line), array_values($get('items') ?? []));

                return __('Bill total').': '.MoneyFormatter::format($calculator->totals($lines)['total_amount']);
            }

            return __('Line total').': '.MoneyFormatter::format($calculator->line($get())['line_total']);
        } catch (ValidationException $exception) {
            return __('Enter valid line amounts to calculate totals.');
        }
    }
}
