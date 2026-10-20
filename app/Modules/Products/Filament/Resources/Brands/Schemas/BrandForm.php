<?php

namespace App\Modules\Products\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Brand overview'))->icon('heroicon-o-tag')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                FileUpload::make('icon')->label(__('Brand icon'))->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)->disk('public')->directory('brands/icons')->visibility('public')->preventFilePathTampering(),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]),
        ]);
    }
}
