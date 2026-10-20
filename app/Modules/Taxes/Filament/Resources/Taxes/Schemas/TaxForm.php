<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes\Schemas;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Taxes\Models\Tax;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class TaxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([Section::make(__('Tax definition'))->columns(2)->columnSpanFull()->schema(self::fields('taxes'))]);
    }

    public static function fields(string $kind, bool $productOnly = false): array
    {
        $tax = $kind === 'taxes';
        $model = $tax ? Tax::class : Discount::class;
        $amount = $tax ? 'rate' : 'value';
        $fields = [
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('code')->label(__('Code'))->required($tax)->maxLength(255)
                ->unique(table: $kind, column: 'code', ignorable: fn (?Model $record): ?Model => $record instanceof $model ? $record : null, ignoreRecord: false),
            Select::make('type')->label(__('Type'))->options(['percentage' => __('Percentage'), 'fixed' => __('Fixed')])->default('percentage')->live()->required(),
            TextInput::make($amount)->label(__($tax ? 'Rate' : 'Value'))->numeric()->minValue(0)
                ->maxValue(fn (Get $get): string => $get('type') === 'percentage' ? '100' : ($tax ? '99999999.9999' : '9999999999999999.9999'))
                ->step('0.0001')->default('0.0000')->required(),
        ];
        if ($tax) {
            return [...$fields,
                Select::make('price_inclusion')->label(__('Price inclusion'))->options(['excluded' => __('Excluded'), 'included' => __('Included')])->default('excluded')->required(),
                Select::make('usage')->label(__('Usage'))->options(['sales' => __('Sales'), 'purchases' => __('Purchases'), 'both' => __('Both')])->default('sales')->required(),
                DatePicker::make('effective_from')->label(__('Effective from')),
                DatePicker::make('effective_to')->label(__('Effective to'))->afterOrEqual(fn (Get $get): ?string => $get('effective_from') ? 'effective_from' : null),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ];
        }

        return [...$fields,
            Select::make('scope')->label(__('Scope'))->options($productOnly ? ['product' => __('Product')] : ['product' => __('Product'), 'order' => __('Order')])->default('product')->required(),
            Select::make('usage')->label(__('Usage'))->options(['sales' => __('Sales'), 'purchases' => __('Purchases'), 'both' => __('Both')])->default('sales')->required(),
            Toggle::make('is_stackable')->label(__('Stackable'))->default(false),
            DateTimePicker::make('starts_at')->label(__('Starts at')),
            DateTimePicker::make('ends_at')->label(__('Ends at'))->afterOrEqual(fn (Get $get): ?string => $get('starts_at') ? 'starts_at' : null),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ];
    }
}
