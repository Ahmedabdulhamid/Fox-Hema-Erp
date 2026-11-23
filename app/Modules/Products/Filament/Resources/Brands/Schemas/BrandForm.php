<?php

namespace App\Modules\Products\Filament\Resources\Brands\Schemas;

use App\Modules\Products\Services\ProductImageManager;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Brand overview'))->icon('heroicon-o-tag')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                FileUpload::make('icon')->label(__('Brand icon'))->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)->disk('public')->directory('brands/icons')->visibility('public')->preventFilePathTampering()
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(ProductImageManager::class)->storeImmutableUpload($file, 'brands/icons')),
                Toggle::make('is_active')->label(__('Active'))->default(true),
            ]),
        ]);
    }
}
