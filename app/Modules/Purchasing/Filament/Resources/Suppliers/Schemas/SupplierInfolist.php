<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('General Information'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('code')->label(__('Supplier code')),
                TextEntry::make('name')->label(__('Supplier name')),
                IconEntry::make('is_active')->label(__('Active'))->boolean(),
            ]),
            Section::make(__('Contact Information'))->columns(2)->columnSpanFull()->schema([
                TextEntry::make('contact')->label(__('Contact person'))->placeholder('—'),
                TextEntry::make('phone')->label(__('Phone'))->placeholder('—'),
                TextEntry::make('email')->label(__('Email'))->placeholder('—'),
                TextEntry::make('address')->label(__('Address'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('Financial Information'))->columns(2)->columnSpanFull()->schema([
                TextEntry::make('tax_number')->label(__('Tax number'))->placeholder('—'),
                TextEntry::make('payment_terms_days')->label(__('Payment terms (days)')),
            ]),
            Section::make(__('Additional Information'))->columnSpanFull()->schema([
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—'),
            ]),
        ]);
    }
}
