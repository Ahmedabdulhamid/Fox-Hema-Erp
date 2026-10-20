<?php

namespace App\Modules\Products\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class ProductImageFields
{
    public static function upload(): FileUpload
    {
        return FileUpload::make('upload')->label(__('Product image'))->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(5120)->storeFiles(false)->preventFilePathTampering();
    }

    public static function metadata(): array
    {
        return [
            TextInput::make('alt_text')->label(__('Alternative text'))->maxLength(255),
            TextInput::make('sort_order')->label(__('Sort order'))->integer()->minValue(0)->maxValue(4294967295)->default(0)->required(),
            Toggle::make('is_primary')->label(__('Primary image'))->default(false),
        ];
    }
}
