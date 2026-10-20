<?php

namespace App\Modules\Products\Filament\Resources\Colors\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ColorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Color overview'))->icon('heroicon-o-swatch')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('sort_order')->label(__('Sort order'))->integer()->minValue(0)->maxValue(4294967295)->default(0)->required(),
                ColorPicker::make('hex_code')->label(__('Color code'))->rules(['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]),
        ]);
    }
}
