<?php

namespace App\Modules\Products\Filament\Resources\Products\Pages;

use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Services\ProductImportAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importExcel')->label(__('product_import.title'))->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => app(ProductImportAccess::class)->allows(auth()->user()))
                ->url(fn (): string => ProductResource::getUrl('import')),
            CreateAction::make(),
        ];
    }
}
