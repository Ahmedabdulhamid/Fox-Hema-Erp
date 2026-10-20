<?php

namespace App\Modules\Products\Filament\Resources\Sizes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SizeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Size overview'))->icon('heroicon-o-arrows-pointing-out')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('sort_order')->label(__('Sort order'))->integer()->minValue(0)->maxValue(4294967295)->default(0)->required(),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]),
        ]);
    }
}
