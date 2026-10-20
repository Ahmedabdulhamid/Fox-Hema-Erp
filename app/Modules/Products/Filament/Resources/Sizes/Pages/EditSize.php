<?php

namespace App\Modules\Products\Filament\Resources\Sizes\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Sizes\SizeResource;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSize extends EditRecord
{
    protected static string $resource = SizeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCatalogManager::class)->save(auth()->user(), $record::class, $data, $record), 'data');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app(ProductCatalogManager::class)->delete(auth()->user(), $record)))];
    }
}
