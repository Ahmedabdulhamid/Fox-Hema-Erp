<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('General Information'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('code')->label(__('Supplier code'))->required()->maxLength(50)->unique(ignoreRecord: true),
                TextInput::make('name')->label(__('Supplier name'))->required()->maxLength(255),
                Toggle::make('is_active')->label(__('Active'))->default(true)->disabled()->dehydrated(false)
                    ->helperText(__('Use the Activate or Deactivate action to change supplier status.')),
            ]),
            Section::make(__('Contact Information'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('contact')->label(__('Contact person'))->maxLength(255),
                TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(30),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(255),
                Textarea::make('address')->label(__('Address'))->rows(3)->maxLength(65535)->columnSpanFull(),
            ]),
            Section::make(__('Financial Information'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('tax_number')->label(__('Tax number'))->maxLength(100),
                TextInput::make('payment_terms_days')->label(__('Payment terms (days)'))->integer()
                    ->minValue(0)->maxValue(65535)->default(0)->required(),
            ]),
            Section::make(__('Additional Information'))->columnSpanFull()->schema([
                Textarea::make('notes')->label(__('Notes'))->rows(4)->maxLength(65535)->columnSpanFull(),
            ]),
        ]);
    }
}
