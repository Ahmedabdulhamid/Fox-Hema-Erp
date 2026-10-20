<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Schemas;

use App\Models\QuantityFormatter;
use App\Modules\Products\Filament\ProductLinePreview;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PurchaseReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Goods Receipt'))->columns(3)->schema([
                TextInput::make('po_number')->label(__('PO number'))->disabled()->dehydrated(false),
                TextInput::make('supplier_name')->label(__('Supplier'))->disabled()->dehydrated(false),
                TextInput::make('destination')->label(__('Destination location'))->disabled()->dehydrated(false),
                DateTimePicker::make('received_at')->label(__('Received date'))->maxDate(now()),
            ]),
            Repeater::make('items')->label(__('Receipt lines'))->addable(false)->deletable(false)->reorderable(false)->columns(3)->schema([
                Hidden::make('id')->required(),
                ProductLinePreview::make(self::class, 'id', fn (Get $get, array $ids, ?Model $record): Collection => $record === null ? collect() : ProductLinePreview::byKeys($record->items()->getQuery(), $ids)->with(['purchaseOrderItem.variant.images', 'purchaseOrderItem.variant.product.images'])->get()->keyBy('id'), 'purchaseOrderItem.variant'),
                TextInput::make('variant_label')->label(__('Product variant'))->disabled()->dehydrated(false),
                TextInput::make('ordered')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Ordered quantity'))->disabled()->dehydrated(false),
                TextInput::make('expected')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Expected quantity'))->disabled()->dehydrated(false),
                TextInput::make('previously_received')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Previously received quantity'))->disabled()->dehydrated(false),
                TextInput::make('remaining')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Remaining quantity'))->disabled()->dehydrated(false),
                TextInput::make('quantity')->label(__('Quantity to receive'))->required()->inputMode('decimal'),
                TextInput::make('unit_cost')->label(__('Actual unit purchase cost (EGP)'))->required()->inputMode('decimal')
                    ->helperText(__('Supplier unit cost excludes tax; review discounts before validation.')),
            ]),
            Textarea::make('notes')->label(__('Notes'))->maxLength(16000),
        ])->columns(1);
    }
}
